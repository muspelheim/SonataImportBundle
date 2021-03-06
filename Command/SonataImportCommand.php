<?php

namespace Doctrs\SonataImportBundle\Command;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMException;
use Doctrs\SonataImportBundle\Entity\CsvFile;
use Doctrs\SonataImportBundle\Entity\ImportLog;
use Doctrs\SonataImportBundle\Loaders\CsvFileLoader;
use Doctrs\SonataImportBundle\Loaders\FileLoaderInterface;
use Doctrs\SonataImportBundle\Service\ImportAbstract;
use Sonata\AdminBundle\Admin\AbstractAdmin;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\HttpFoundation\File\File;

class SonataImportCommand extends ContainerAwareCommand{

    /** @var EntityManager $this->em  */
    protected $em;

    protected function configure() {
        $this
            ->setName('doctrs:sonata:import')
            ->setDescription('Import data to sonata from CSV')
            ->addArgument('csv_file', InputArgument::REQUIRED, 'id CsvFile entity')
            ->addArgument('admin_code', InputArgument::REQUIRED, 'code to sonata admin bundle')
            ->addArgument('encode', InputArgument::OPTIONAL, 'file encode')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output) {

        $this->em = $this->getContainer()->get('doctrine')->getManager();
        $csvFileId = $input->getArgument('csv_file');
        $adminCode = $input->getArgument('admin_code');
        $encode = strtolower($input->getArgument('encode'));

        /** @var CsvFile $csvFile */
        $csvFile = $this->em->getRepository('DoctrsSonataImportBundle:CsvFile')->find($csvFileId);
        $fileLoader = $this->getContainer()->getParameter('doctrs_sonata_import.class_loader');

        if(!class_exists($fileLoader)){
            $csvFile->setStatus(CsvFile::STATUS_ERROR);
            $csvFile->setMessage('class_loader not found');
            $this->em->flush($csvFile);
            return;
        }
        $fileLoader = new $fileLoader();
        if(!$fileLoader instanceof FileLoaderInterface){
            $csvFile->setStatus(CsvFile::STATUS_ERROR);
            $csvFile->setMessage('class_loader must be instanceof "FileLoaderInterface"');
            $this->em->flush($csvFile);
            return;
        }


        try {
            $fileLoader = new CsvFileLoader();
            $fileLoader->setFile(new File($csvFile->getFile()));

            $pool = $this->getContainer()->get('sonata.admin.pool');
            /** @var AbstractAdmin $instance */
            $instance = $pool->getInstance($adminCode);
            $entityClass = $instance->getClass();
            $meta = $this->em->getClassMetadata($entityClass);
            $identifier = $meta->getSingleIdentifierFieldName();
            $exportFields = $instance->getExportFields();
            $form = $instance->getFormBuilder();
            foreach ($fileLoader->getIteration() as $line => $data) {

                $log = new ImportLog();
                $log
                    ->setLine($line)
                    ->setCsvFile($csvFile)
                ;

                $entity = new $entityClass();
                $errors = [];
                foreach ($exportFields as $key => $name) {
                    $value = isset($data[$key]) ? $data[$key] : '';

                    /**
                     * В случае если указан ID (первый столбец)
                     * ищем сущность в базе
                     */
                    if ($name === $identifier) {
                        if ($value) {
                            $oldEntity = $instance->getObject($value);
                            if ($oldEntity) {
                                $entity = $oldEntity;
                            }
                        }
                        continue;
                    }
                    /**
                     * Поля форм не всегда соответствуют тому, что есть на сайте, и что в админке
                     * Поэтому если поле не указано в админке, то просто пропускаем его
                     */
                    if (!$form->has($name)) {
                        continue;
                    }
                    $formBuilder = $form->get($name);
                    /**
                     * Многие делают ошибки в стандартной кодировке,
                     * поэтому на всякий случай провверяем оба варианта написания
                     */
                    if ($encode !== 'utf8' && $encode !== 'utf-8') {
                        $value = iconv($encode, 'utf8//TRANSLIT', $value);
                    }
                    try {
                        $value = $this->setValue($value, $formBuilder, $instance);
                        $method = $this->getSetMethod($name);
                        $entity->$method($value);
                    } catch (\Exception $e) {
                        $errors[] = $e->getMessage();
                        break;
                    }

                }
                if(!count($errors)) {
                    $validator = $this->getContainer()->get('validator');
                    $errors = $validator->validate($entity);
                }

                if (!count($errors)) {
                    $idMethod = $this->getSetMethod($identifier, 'get');
                    /**
                     * Если у сещности нет ID, то она новая - добавляем ее
                     */
                    if (!$entity->$idMethod()) {
                        $this->em->persist($entity);
                        $log->setStatus(ImportLog::STATUS_SUCCESS);
                    } else {
                        $log->setStatus(ImportLog::STATUS_EXISTS);
                    }
                    $this->em->flush($entity);
                    $log->setForeignId($entity->$idMethod());
                } else {
                    $log->setMessage(json_encode($errors));
                    $log->setStatus(ImportLog::STATUS_ERROR);
                }
                $this->em->persist($log);
                $this->em->flush($log);
            }
            $csvFile->setStatus(CsvFile::STATUS_SUCCESS);
            $this->em->flush($csvFile);
        } catch(\Exception $e){
            /**
             * Данный хак нужен в случае бросания ORMException
             * В случае бросания ORMException entity manager останавливается
             * и его требуется перезагрузить
             */
            if (!$this->em->isOpen()) {
                $this->em = $this->em->create(
                    $this->em->getConnection(),
                    $this->em->getConfiguration()
                );
                $csvFile = $this->em->getRepository('DoctrsSonataImportBundle:CsvFile')->find($csvFileId);
            }

            $csvFile->setStatus(CsvFile::STATUS_ERROR);
            $csvFile->setMessage($e->getMessage());
            $this->em->flush($csvFile);
        }
    }


    protected function getSetMethod($name, $method = 'set') {
        return $method . str_replace(' ', '', ucfirst(join('', explode('_', $name))));
    }

    protected function setValue($value, FormBuilderInterface $fieldDescription, AbstractAdmin $admin){

        $mappings = $this->getContainer()->getParameter('doctrs_sonata_import.mappings');

        $originalValue = $value;
        $field = $fieldDescription->getName();
        $type = $fieldDescription->getType()->getName();

        /**
         * Проверяем кастомные типы форм на наличие в конфиге.
         * В случае совпадения, получаем значение из класса, указанного в конфиге
         */
        foreach($mappings as $item){
            if($item['name'] === $type){
                if($this->getContainer()->has($item['class']) && $this->getContainer()->get($item['class']) instanceof ImportAbstract){
                    /** @var ImportAbstract $class */
                    $class = $this->getContainer()->get($item['class']);
                    return $class->getFormatValue($value);
                }
            }
        }

        if ($type === 'date' || $type === 'datetime') {
            $value = $value ? new \DateTime($value) : null;
        }
        if ($type === 'boolean') {
            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }
        if ($type === 'integer') {
            $value = (int)$value;
        }

        if (
            (
                $type === 'choice' &&
                $fieldDescription->getOption('class')
            ) || (
                $type === 'entity' &&
                $fieldDescription->getOption('class')
            )
        ) {
            if(!$value){
                return null;
            }
            /** @var \Doctrine\ORM\Mapping\ClassMetadata $metaData */
            $metaData = $admin->getModelManager()
                ->getEntityManager($admin->getClass())->getClassMetadata($admin->getClass());
            $associations = $metaData->getAssociationNames();

            if (!in_array($field, $associations)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Unknown association "%s", association does not exist in entity "%s", available associations are "%s".',
                        $field,
                        $admin->getClass(),
                        implode(', ', $associations)
                    )
                );
            }

            $repo = $admin->getConfigurationPool()->getContainer()->get('doctrine')->getManager()
                ->getRepository($fieldDescription->getOption('class'));

            /**
             * Если значение число, то пытаемся найти его по ID.
             * Если значение не число, то ищем его по полю name
             */
            if(is_numeric($value)){
                $value = $repo->find($value);
            } else {
                try {
                    $value = $repo->findOneBy([
                        'name' => $value
                    ]);
                } catch (ORMException $e) {
                    throw new InvalidArgumentException('Field name not found');
                }
            }

            if (!$value) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Edit failed, object with id "%s" not found in association "%s".',
                        $originalValue,
                        $field)
                );
            }
        }
        return $value;
    }
}
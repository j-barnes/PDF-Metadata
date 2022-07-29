<?php

namespace Drupal\pdf_metadata\Service;

use Drupal\token\TokenEntityMapperInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\Entity\ThirdPartySettingsInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\file\Plugin\Field\FieldType\FileFieldItemList;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\token\TokenInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Field\EntityReferenceFieldItemList;
use Psr\Log\LoggerInterface;
use Monolog\Logger;
use PHPExiftool\Writer;
use PHPExiftool\Driver\Metadata\Metadata;
use PHPExiftool\Driver\Tag\PDF\Title;
use PHPExiftool\Driver\Tag\PDF\Author;
use PHPExiftool\Driver\Tag\PDF\Subject;
use PHPExiftool\Driver\Tag\PDF\Keywords;
use PHPExiftool\Driver\Value\Mono;
use PHPExiftool\Driver\Value\Multi;
use PHPExiftool\Reader;

/**
 * Provides a service with helper functions useful during metadata creation.
 */
class MetadataCreationService {

  use StringTranslationTrait;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Token entity type mapper service.
   *
   * @var \Drupal\token\TokenEntityMapperInterface
   */
  protected $tokenEntityMapper;

  /**
   * The token service.
   *
   * @var \Drupal\Core\Utility\Token
   */
  protected $token;

  /**
   * The file_system service.
   *
   * @var \Drupal\Core\File\FileSystem
   */
  protected $fileSystem;


  /**
   * A logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Class constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\token\TokenEntityMapperInterface $token_entity_mapper
   *   The token entity type mapper service.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\token\TokenInterface $token
   *   The token.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, TokenEntityMapperInterface $token_entity_mapper, FileSystemInterface $file_system, TokenInterface $token, LoggerInterface $logger) {
    $this->entityTypeManager = $entity_type_manager;
    $this->tokenEntityMapper = $token_entity_mapper;
    $this->fileSystem = $file_system;
    $this->token = $token;
    $this->logger = $logger;
  }

  /**
   * Check for replacement tokens.
   *
   * @param mixed $entity
   *   The entity to provide token replacements for.
   * @param array $settings
   *   The settings for the given entity.
   *
   * @return array
   *   An array of the replaced tokens for each meta type.
   */
  public function checkReplacementTokens(mixed $entity, array $settings): array {
    $metadata = [];
    $token_data = [$entity->getEntityTypeId() => $entity];
    foreach (array_keys($settings['meta_types']) as $type) {
      $metadata[$type] = $this->token->replace($settings['meta_types'][$type], $token_data, ['clear' => TRUE]);
    }
    return $metadata;
  }

  /**
   * Check if the given reference field has PDF Metadata Reference Enabled.
   *
   * @param mixed $references
   *   References to validate if PDF Metadata Reference is enabled.
   *
   * @return array
   *   Returns file fields with PDF Metadata References enabled.
   */
  public function validateReferenceFields(mixed $references): array {
    $file_fields = [];
    foreach ($references as $reference) {
      foreach ($reference['field']->referencedEntities() as $ref_entity) {
        if (!$ref_entity instanceof ContentEntityInterface) {
          continue;
        }
        foreach ($ref_entity->getFields() as $ref_field) {
          $settings = $this->getThirdPartySettings($ref_field);

          // Only replace references with 'reference_enabled' set to TRUE.
          if ($ref_field instanceof FileFieldItemList && $settings && $settings['reference_enabled']) {

            // We need to pass the reference entity field to be checked.
            $reference['entity'] = $ref_entity;
            $reference['field'] = $ref_field;
            $file_fields[] = $reference;
          }
        }
      }
    }
    return $file_fields;
  }

  /**
   * Check if the given file field has PDF Metadata enabled.
   *
   * @param mixed $entity
   *   Entity that was updated and should be checked for PDF Metadata.
   *
   * @return array
   *   Array of file fields with PDF Metadata enabled separated by type.
   */
  public function validateFields(mixed $entity): array {
    $fields = ['file_fields' => [], 'reference_fields' => []];
    if ($entity instanceof ContentEntityInterface) {
      foreach ($entity->getFields() as $field) {
        $settings = $this->getThirdPartySettings($field);

        // Only add items with 'enabled' set to TRUE.
        if (!$settings || !$settings['enabled']) {
          continue;
        }

        if ($field instanceof EntityReferenceFieldItemList) {
          $fields['reference_fields'][] = [
            'field' => $field,
            'settings' => $settings,
            'parent_entity' => $entity,
          ];
        }

        if ($field instanceof FileFieldItemList) {
          $fields['file_fields'][] = [
            'entity' => $entity,
            'field' => $field,
            'settings' => $settings,
          ];
        }
      }
    }
    return $fields;
  }

  /**
   * Get files, check mime types and get token values.
   *
   * @param array $file_fields
   *   Combined file fields for validating.
   *
   * @return array
   *   Combined files with metadata.
   */
  public function validateFiles(array $file_fields): array {
    $files = [];
    foreach ($file_fields as $item) {
      foreach ($item['field']->referencedEntities() as $file) {

        // Only replace if the file is a PDF, otherwise continue loop.
        if ($file->getMimeType() !== 'application/pdf') {
          continue;
        }

        $files[] = [
          'file' => $file,
          'metadata' => $this->checkReplacementTokens($item['parent_entity'] ?? $item['entity'], $item['settings']),
        ];
      }
    }
    return $files;
  }

  /**
   * Generate PDF for the given files with metadata.
   *
   * @param array $files
   *   Array of files with metadata.
   */
  public function generatePdfWithMetadata(array $files): void {
    foreach ($files as $file) {
      if (!file_exists($file['file']->getFileUri())) {
        $this->logger->error('Error reading file from @file_uri.', [
          '@file_uri' => $file['file']->getFileUri(),
        ]);
        continue;
      }

      // Catch all block in case of issues saving / converting.
      try {
        $logger = new Logger('exiftool');
        $reader = Reader::create($logger);
        $writer = Writer::create($logger);
        $file_path = $this->fileSystem->realpath($file['file']->getFileUri());
        $metadata = $reader->files($file_path)->first()->getMetadatas();

        // We need to clear any previous metadata that might be in old format.
        $title = $metadata->get('PDF:Title');
        $title ? $title->getValue()->set('') : '';

        $author = $metadata->get('PDF:Author');
        $author ? $author->getValue()->set('') : '';

        $subject = $metadata->get('PDF:Subject');
        $subject ? $subject->getValue()->set('') : '';

        $keywords = $metadata->get('PDF:Keywords');
        $keywords ? $keywords->getValue()->set('') : '';

        // Add new metadata.
        $metadata->add(new Metadata(new Author(), new Mono($file['metadata']['author'])));
        $metadata->add(new Metadata(new Title(), new Mono($file['metadata']['title'])));
        $metadata->add(new Metadata(new Subject(), new Mono($file['metadata']['subject'])));
        $metadata->add(new Metadata(new Keywords(), new Multi($file['metadata']['keywords'])));

        $writer->write($file_path, $metadata);
      }
      catch (\Exception $e) {
        $this->logger->error('Error setting / writing metadata to PDF to @file_uri. See @error.', [
          '@file_uri' => $file['file']->getFileUri(),
          '@error' => $e,
        ]);
      }

    }
  }

  /**
   * This function will perform all of the functions needed to append metadata.
   *
   * @param mixed $entity
   *   The entity that triggered the event.
   */
  public function appendMetadata(mixed $entity): void {
    $fields = $this->validateFields($entity);
    $file_fields = $this->validateReferenceFields($fields['reference_fields']);
    $files = $this->validateFiles(array_merge($fields['file_fields'], $file_fields));

    // Generate PDFs with all the metadata.
    $this->generatePdfWithMetadata($files);
  }

  /**
   * Check if the field contains third party settings and return them.
   *
   * @param mixed $field
   *   The field to check for third party settings.
   *
   * @return array
   *   Returns pdf_metadata from third party settings.
   */
  public function getThirdPartySettings(mixed $field): array {
    $definition = $field->getFieldDefinition();
    if (!isset($definition) || !($definition instanceof ThirdPartySettingsInterface)) {
      return [];
    }
    return $definition->getThirdPartySettings('pdf_metadata');
  }

  /**
   * Utility function to add the metadata form fields to the form.
   *
   * @param mixed $form
   *   Form that we are currently configuring.
   * @param mixed $form_state
   *   Form state that we are currently configuring.
   */
  public function addMetaFormFields(&$form, $form_state): void {
    /** @var Drupal\field\Entity\FieldConfig $field */
    $field = $form_state->getFormObject()->getEntity();
    $definition = $field->getItemDefinition();
    $class = $field->getClass();

    // If the item is not an entity reference or file field we return.
    if (!class_exists($class) || (!(new $class($definition) instanceof EntityReferenceFieldItemList || !(new $class($definition) instanceof FileFieldItemList)))) {
      return;
    }

    $settings = $field->getThirdPartySettings('pdf_metadata');
    $entity_info = $this->entityTypeManager->getDefinition($field->getTargetEntityTypeId());
    $type = $this->tokenEntityMapper->getTokenTypeForEntityType($entity_info->id());

    $form['settings']['pdf_metadata'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#weight' => 2,
      '#parents' => ['third_party_settings', 'pdf_metadata'],
    ];

    // Only show this option for entities that can be referenced.
    $form['settings']['pdf_metadata']['reference_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable PDF Metadata for Referencing Entities?'),
      '#default_value' => $settings['reference_enabled'] ?? FALSE,
      '#description' => $this->t('This allows other entity reference fields to write metadata to this field.'),
      '#states' => [
        'disabled' => [
          ':input[name="third_party_settings[pdf_metadata][enabled]"]' => ['checked' => TRUE],
        ],
        'visible' => [
          ':input[name="third_party_settings[pdf_metadata][type]"]' => ['value' => 'media'],
        ],
      ],
    ];

    $form['settings']['pdf_metadata']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable PDF Metadata?'),
      '#default_value' => $settings['enabled'] ?? FALSE,
      '#description' => $this->t('PDF Metadata allows for automatic metadata appending.'),
      '#states' => [
        'disabled' => [
          ':input[name="third_party_settings[pdf_metadata][reference_enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['settings']['pdf_metadata']['details'] = [
      '#type' => 'details',
      '#title' => $this->t('PDF Metadata Settings'),
      '#weight' => 3,
      '#tree' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="third_party_settings[pdf_metadata][enabled]"]' => ['checked' => TRUE],
        ],
      ],
      '#parents' => ['third_party_settings', 'pdf_metadata'],
    ];

    $token_default = $type == 'node' ? '[' . $type . ':title]' : '[' . $type . ':name:value]';

    $form['settings']['pdf_metadata']['details']['meta_types']['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#maxlength' => 512,
      '#size' => 128,
      '#default_value' => $settings['meta_types']['title'] ?? $token_default,
    ];

    $form['settings']['pdf_metadata']['details']['meta_types']['author'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Author'),
      '#maxlength' => 512,
      '#size' => 128,
      '#default_value' => $settings['meta_types']['author'] ?? '',
    ];

    $form['settings']['pdf_metadata']['details']['meta_types']['subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subject'),
      '#maxlength' => 512,
      '#size' => 128,
      '#default_value' => $settings['meta_types']['subject'] ?? '',
    ];

    $form['settings']['pdf_metadata']['details']['meta_types']['keywords'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Keywords'),
      '#maxlength' => 512,
      '#size' => 128,
      '#default_value' => $settings['meta_types']['keywords'] ?? $token_default,
    ];

    $form['settings']['pdf_metadata']['details']['type'] = [
      '#type' => 'hidden',
      '#default_value' => $settings['type'] ?? $type,
    ];

    $form['settings']['pdf_metadata']['details']['token_tree'] = [
      '#theme' => 'token_tree_link',
      '#token_types' => [''],
      '#weight' => 10,
    ];
    $form['settings']['pdf_metadata']['details']['token_tree']['#token_types'][] = $settings['type'] ?? $type;
  }

}

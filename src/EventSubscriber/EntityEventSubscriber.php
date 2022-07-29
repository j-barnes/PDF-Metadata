<?php

namespace Drupal\pdf_metadata\EventSubscriber;

use Drupal\pdf_metadata\Service\MetadataCreationService;
use Drupal\core_event_dispatcher\Event\Entity\EntityUpdateEvent;
use Drupal\core_event_dispatcher\Event\Entity\EntityInsertEvent;
use Drupal\core_event_dispatcher\Event\Form\FormIdAlterEvent;
use Drupal\core_event_dispatcher\EntityHookEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * PDF Metadata Entity Event Subscriber.
 */
class EntityEventSubscriber implements EventSubscriberInterface {

  /**
   * The PDF Metadata creation service.
   *
   * @var \Drupal\pdf_metadata\Service\MetadataCreationService
   */
  protected $metadataCreationService;

  /**
   * Creates a new EntityEventSubscriber instance.
   *
   * @param \Drupal\pdf_metadata\Service\MetadataCreationService $metadata_creation_service
   *   The PDF Metadata creation service.
   */
  public function __construct(MetadataCreationService $metadata_creation_service) {
    $this->metadataCreationService = $metadata_creation_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      EntityHookEvents::ENTITY_UPDATE => 'entityUpdate',
      EntityHookEvents::ENTITY_INSERT => 'entityInsert',
      'hook_event_dispatcher.form_field_config_edit_form.alter' => 'alterConfigForm',
    ];
  }

  /**
   * On entity update we call our service to append metadata to the PDF.
   *
   * @param \Drupal\core_event_dispatcher\Event\Entity\EntityUpdateEvent $event
   *   The event passed from entity update.
   */
  public function entityUpdate(EntityUpdateEvent $event): void {
    $this->metadataCreationService->appendMetadata($event->getEntity());
  }

  /**
   * On entity insert we call our service to append metadata to the PDF.
   *
   * This is required for newly created items.
   *
   * @param \Drupal\core_event_dispatcher\Event\Entity\EntityInsertEvent $event
   *   The event passed from entity insert.
   */
  public function entityInsert(EntityInsertEvent $event): void {
    $this->metadataCreationService->appendMetadata($event->getEntity());
  }

  /**
   * On configuration forms we need to add new field forms.
   *
   * @param \Drupal\core_event_dispatcher\Event\Form\FormIdAlterEvent $event
   *   The event passed from entity config form event.
   */
  public function alterConfigForm(FormIdAlterEvent $event): void {
    $this->metadataCreationService->addMetaFormFields($event->getForm(), $event->getFormState());
  }

}

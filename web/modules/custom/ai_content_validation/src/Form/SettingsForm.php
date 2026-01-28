<?php

declare(strict_types=1);

namespace Drupal\ai_content_validation\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drush\Commands\AutowireTrait;

/**
 * Configure Ai content validation settings for this site.
 */
final class SettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    protected EntityTypeManagerInterface $entityTypeManager
  )
  {
    return parent::__construct($config_factory, $typedConfigManager);
  }

  use AutowireTrait;

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_content_validation_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['ai_content_validation.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $entities = $this->entityTypeManager->getStorage('flowdrop_workflow')->loadMultiple();
    $flows = $this->config('ai_content_validation.settings')->get('flows') ?? [];
    // @todo Display an error if saved flows correspond to non-existing entities.
    $options = [];
    foreach($entities as $entity) {
      $options[$entity->id()] = $entity->label();
    }

    $form['flows'] = [
      '#type' => 'checkboxes',
      '#options' => $options,
      '#title' => $this->t('Which flows do you want to enable?'),
      '#empty' => $this->t('No flows available.'),
      '#default_value' => $this->config('ai_content_validation.settings')->get('flows'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('ai_content_validation.settings')
      ->set('flows', array_values($form_state->getValue('flows')))
      ->save();
    parent::submitForm($form, $form_state);
  }

}

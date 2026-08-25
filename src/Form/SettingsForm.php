<?php

declare(strict_types=1);

namespace Drupal\decoupled_settings\Form;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\decoupled_settings\SettingsMerger;
use Drupal\decoupled_settings\SettingsResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Chooses which config is exposed to decoupled frontends.
 *
 * Config objects are added from a list of everything the site holds, the way
 * core's single-export form offers them, rather than typed by machine name.
 * The review table at the bottom always reflects the current selections,
 * saved or not, so the effect of a change is visible before it is committed.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * The settings resolver.
   */
  protected SettingsResolver $resolver;

  /**
   * The settings merger.
   */
  protected SettingsMerger $merger;

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->resolver = $container->get('decoupled_settings.resolver');
    $instance->merger = $container->get('decoupled_settings.merger');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'decoupled_settings_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['decoupled_settings.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('decoupled_settings.settings');
    $exposed = $form_state->get('exposed_working');
    if ($exposed === NULL) {
      $exposed = $config->get('exposed_objects') ?: [];
      $form_state->set('exposed_working', $exposed);
    }

    $form['#attached']['library'][] = 'decoupled_settings/settings-filter';

    $form['exposed'] = [
      '#type' => 'details',
      '#title' => $this->t('Exposed configuration'),
      '#open' => TRUE,
    ];

    // The add-new row lives inside the table, select and button in one
    // cell, the way the image style effects form does it.
    $rows = [];
    foreach ($exposed as $name) {
      $rows[$name] = [
        'name' => ['#markup' => $name],
        'operations' => [
          '#type' => 'submit',
          '#value' => $this->t('Remove'),
          '#name' => 'remove__' . $name,
          '#submit' => ['::removeObject'],
          '#limit_validation_errors' => [],
          '#attributes' => ['class' => ['button--small']],
        ],
      ];
    }
    $rows['_new'] = [
      '#tree' => FALSE,
      'name' => [
        'data' => [
          'add_object' => [
            '#type' => 'select',
            '#title' => $this->t('Add configuration'),
            '#title_display' => 'invisible',
            '#options' => $this->availableObjects($exposed),
            '#empty_option' => $this->t('- Select a config object -'),
          ],
          [
            'add_submit' => [
              '#type' => 'submit',
              '#value' => $this->t('Add'),
              '#submit' => ['::addObject'],
              '#limit_validation_errors' => [['add_object']],
              '#attributes' => ['class' => ['button--small']],
            ],
          ],
        ],
        '#wrapper_attributes' => ['class' => ['decoupled-settings-new']],
      ],
      'operations' => ['data' => []],
    ];
    $form['exposed']['objects'] = [
      '#type' => 'table',
      '#header' => [$this->t('Config object'), $this->t('Operations')],
      '#empty' => $this->t('Nothing is exposed yet. Add a config object below.'),
    ] + $rows;

    $form['expose_theme_settings'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Expose the active theme settings'),
      '#description' => $this->t('Adds the active theme automatically, so the list above does not need editing when the theme changes.'),
      '#default_value' => (bool) $config->get('expose_theme_settings'),
    ];

    $form['excluded_keys'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Excluded settings'),
      '#description' => $this->t('Settings that are never exposed, even though their schema declares them. One per line, as <code>object:path</code>, for example <code>system.site:mail</code>.'),
      '#default_value' => implode("\n", $config->get('excluded_keys') ?: []),
      '#rows' => 2,
    ];

    $form['review'] = [
      '#type' => 'details',
      '#title' => $this->t('What a frontend will read'),
      '#description' => $this->t('Reflects the selections above, including changes that are not saved yet.'),
      '#open' => TRUE,
    ];
    $form['review']['filter'] = [
      '#type' => 'search',
      '#title' => $this->t('Filter settings'),
      '#title_display' => 'invisible',
      '#placeholder' => $this->t('Filter by setting name or value'),
      '#attributes' => [
        'data-decoupled-settings-filter' => '#decoupled-settings-review',
        'autocomplete' => 'off',
      ],
      '#size' => 40,
    ];
    $form['review']['table'] = $this->buildReview($form_state, $exposed);

    return parent::buildForm($form, $form_state);
  }

  /**
   * Adds the selected config object to the working list.
   */
  public function addObject(array &$form, FormStateInterface $form_state): void {
    $name = (string) $form_state->getValue('add_object');
    if ($name !== '') {
      $exposed = $form_state->get('exposed_working');
      $exposed[] = $name;
      $form_state->set('exposed_working', array_values(array_unique($exposed)));
    }
    $form_state->setRebuild();
  }

  /**
   * Removes one config object from the working list.
   */
  public function removeObject(array &$form, FormStateInterface $form_state): void {
    $name = substr((string) $form_state->getTriggeringElement()['#name'], strlen('remove__'));
    $exposed = array_values(array_diff($form_state->get('exposed_working'), [$name]));
    $form_state->set('exposed_working', $exposed);
    $form_state->setRebuild();
  }

  /**
   * Lists the simple config objects that could be exposed, grouped by owner.
   *
   * Config entities are left out on purpose: they are entities, so core
   * JSON:API can already serve them. This module exists for the simple
   * config that core JSON:API cannot reach. An object with no schema is
   * labelled rather than hidden, so choosing it is an informed act instead
   * of a mystery.
   */
  protected function availableObjects(array $exposed): array {
    $typed = $this->typedConfigManager();
    $entity_prefixes = $this->configEntityPrefixes();
    $options = [];

    foreach ($this->configFactory()->listAll() as $name) {
      if (in_array($name, $exposed, TRUE)) {
        continue;
      }
      foreach ($entity_prefixes as $prefix) {
        if (str_starts_with($name, $prefix)) {
          continue 2;
        }
      }
      $group = explode('.', $name)[0];
      $label = $name;
      if (!$typed->hasConfigSchema($name)) {
        $label .= ' (' . $this->t('no schema, exposes nothing') . ')';
      }
      $options[$group][$name] = $label;
    }
    ksort($options);
    foreach ($options as &$set) {
      ksort($set);
    }

    return $options;
  }

  /**
   * Lists the config prefixes owned by config entity types.
   */
  protected function configEntityPrefixes(): array {
    $prefixes = [];
    foreach ($this->entityTypeManager->getDefinitions() as $definition) {
      if ($definition instanceof ConfigEntityTypeInterface) {
        $prefixes[] = $definition->getConfigPrefix() . '.';
      }
    }

    return $prefixes;
  }

  /**
   * Shows the settings a frontend would read with the current selections.
   *
   * A key omitted for lack of schema disappears silently, so showing the
   * resolved output is the only way an administrator can tell the difference
   * between "not exposed" and "not declared".
   */
  protected function buildReview(FormStateInterface $form_state, array $exposed): array {
    // Read the unsaved checkbox and exclusions when the form was rebuilt by
    // Add or Remove, and the saved values on first view.
    $input = $form_state->getUserInput();
    $config = $this->config('decoupled_settings.settings');
    $theme = array_key_exists('expose_theme_settings', $input)
      ? (bool) $input['expose_theme_settings']
      : (bool) $config->get('expose_theme_settings');
    $excluded = array_key_exists('excluded_keys', $input)
      ? $this->lines((string) $input['excluded_keys'])
      : ($config->get('excluded_keys') ?: []);

    $globals = $this->resolver->globals($exposed, $theme, $excluded, new CacheableMetadata());

    $rows = [];
    foreach ($globals as $name => $values) {
      foreach ($this->merger->flatten($values) as $path => $value) {
        $rows[] = [$name, $path, $this->formatValue($value)];
      }
    }

    return [
      '#type' => 'table',
      '#attributes' => ['id' => 'decoupled-settings-review'],
      '#header' => [
        [
          'data' => $this->t('Config object'),
          'class' => [RESPONSIVE_PRIORITY_MEDIUM],
        ],
        $this->t('Setting'),
        $this->t('Global value'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('Nothing is exposed.'),
    ];
  }

  /**
   * Renders a setting value for display.
   */
  protected function formatValue(mixed $value): string {
    if (is_bool($value)) {
      return $value ? 'true' : 'false';
    }
    if ($value === NULL) {
      return 'null';
    }
    if (is_array($value)) {
      return '[' . implode(', ', array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '...', $value)) . ']';
    }
    return (string) $value;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    foreach ($this->lines($form_state->getValue('excluded_keys')) as $key) {
      if (!str_contains($key, ':')) {
        $form_state->setErrorByName('excluded_keys', $this->t('%key must be written as object:path.', ['%key' => $key]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('decoupled_settings.settings')
      ->set('exposed_objects', $form_state->get('exposed_working') ?: [])
      ->set('expose_theme_settings', (bool) $form_state->getValue('expose_theme_settings'))
      ->set('excluded_keys', $this->lines($form_state->getValue('excluded_keys')))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Splits a textarea value into a list of trimmed, non-empty lines.
   */
  protected function lines(?string $value): array {
    if ($value === NULL || trim($value) === '') {
      return [];
    }

    return array_values(array_filter(array_map(trim(...), preg_split('/\R/', $value) ?: [])));
  }

}

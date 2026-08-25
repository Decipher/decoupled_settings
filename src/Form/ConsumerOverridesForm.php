<?php

declare(strict_types=1);

namespace Drupal\decoupled_settings\Form;

use Drupal\consumers\Entity\ConsumerInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\decoupled_settings\SettingsMerger;
use Drupal\decoupled_settings\SettingsResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Edits the settings one consumer overrides.
 *
 * Every exposed setting is listed with the value it inherits, so the
 * difference between "inherited" and "overridden" is visible rather than
 * implied by an empty field.
 */
final class ConsumerOverridesForm extends FormBase {

  /**
   * The settings resolver.
   */
  protected SettingsResolver $resolver;

  /**
   * The settings merger.
   */
  protected SettingsMerger $merger;

  /**
   * The consumer being edited.
   */
  protected ?ConsumerInterface $consumer = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = new self();
    $instance->resolver = $container->get('decoupled_settings.resolver');
    $instance->merger = $container->get('decoupled_settings.merger');
    $instance->setStringTranslation($container->get('string_translation'));
    $instance->setMessenger($container->get('messenger'));
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'decoupled_settings_consumer_overrides';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?ConsumerInterface $consumer = NULL): array {
    $this->consumer = $consumer;

    // The global values, with no consumer, are what an unoverridden setting
    // resolves to.
    $globals = $this->resolver->resolve(NULL, new CacheableMetadata());
    $overrides = $this->currentOverrides();

    $form['#attached']['library'][] = 'decoupled_settings/settings-filter';

    $form['help'] = [
      '#markup' => $this->t('Tick a setting to override it for this consumer. Anything left unticked is inherited, and changes when the site value changes.'),
    ];

    $form['filter'] = [
      '#type' => 'search',
      '#title' => $this->t('Filter settings'),
      '#title_display' => 'invisible',
      '#placeholder' => $this->t('Filter by setting name or value'),
      '#attributes' => [
        'data-decoupled-settings-filter' => '#decoupled-settings-overrides',
        'autocomplete' => 'off',
      ],
      '#size' => 40,
    ];

    $form['settings'] = [
      '#type' => 'table',
      '#attributes' => ['id' => 'decoupled-settings-overrides'],
      '#header' => [
        $this->t('Setting'),
        [
          'data' => $this->t('Inherited value'),
          'class' => [RESPONSIVE_PRIORITY_MEDIUM],
        ],
        $this->t('Override'),
        $this->t('Value for this consumer'),
      ],
      '#empty' => $this->t('Nothing is exposed, so there is nothing to override.'),
    ];

    foreach ($globals as $name => $values) {
      foreach ($this->merger->flatten($values) as $path => $global) {
        $key = $name . ':' . $path;
        $overridden = array_key_exists($key, $overrides);
        // A non-scalar value has no sensible single-field widget, so it is
        // shown but not editable here rather than mangled into a string.
        $editable = $global === NULL || is_scalar($global);
        $row = ['#tree' => TRUE];

        $row['label'] = ['#markup' => $key];
        $row['inherited'] = ['#markup' => $this->formatValue($global)];
        $row['enabled'] = [
          '#type' => 'checkbox',
          '#default_value' => $overridden,
          '#title' => $this->t('Override @key', ['@key' => $key]),
          '#title_display' => 'invisible',
          '#disabled' => !$editable,
        ];
        $row['value'] = $this->valueWidget($key, $global, $overridden ? $overrides[$key] : NULL, $editable);

        $form['settings'][$key] = $row;
      }
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save overrides'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $globals = $this->resolver->resolve(NULL, new CacheableMetadata());
    $rows = $form_state->getValue('settings') ?: [];

    foreach ($rows as $key => $row) {
      if (empty($row['enabled'])) {
        continue;
      }
      $submitted = (string) ($row['value'] ?? '');
      [$name, $path] = explode(':', (string) $key, 2);
      $global = $this->merger->flatten($globals[$name] ?? [])[$path] ?? NULL;

      // The checkbox and number widgets constrain their own input. The one
      // case they cannot express is a number overridden with nothing.
      if ((is_int($global) || is_float($global)) && $submitted === '') {
        $form_state->setErrorByName("settings][$key][value", $this->t('@key needs a number.', ['@key' => $key]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $globals = $this->resolver->resolve(NULL, new CacheableMetadata());
    $rows = $form_state->getValue('settings') ?: [];
    $overrides = [];

    foreach ($rows as $key => $row) {
      if (empty($row['enabled'])) {
        // Unticked means inherited. The key is left out entirely rather than
        // stored as an empty value, which would mean "deliberately blank".
        continue;
      }
      $overrides[$key] = $this->castToGlobalType($key, (string) $row['value'], $globals);
    }

    // Always one item, even empty: a field with no items stores NULL, which
    // Drupal 10 unserializes without a null guard on every following load.
    $this->consumer->set(SettingsResolver::OVERRIDE_FIELD, [$overrides]);
    $this->consumer->save();

    $this->messenger()->addStatus($this->formatPlural(
      count($overrides),
      'Saved 1 override for @label.',
      'Saved @count overrides for @label.',
      ['@label' => $this->consumer->label()]
    ));
  }

  /**
   * Reads the overrides the consumer currently holds.
   */
  protected function currentOverrides(): array {
    if (!$this->consumer instanceof ConsumerInterface
      || !$this->consumer->hasField(SettingsResolver::OVERRIDE_FIELD)) {
      return [];
    }

    $field = $this->consumer->get(SettingsResolver::OVERRIDE_FIELD);
    if ($field->isEmpty()) {
      return [];
    }

    $stored = $field->first()->getValue();
    return is_array($stored) ? $stored : [];
  }

  /**
   * Casts a submitted string back to the type of the value it overrides.
   *
   * A boolean setting submitted as the string "0" must be stored as FALSE,
   * not as a non-empty string, or the frontend receives the wrong type.
   */
  protected function castToGlobalType(string $key, string $submitted, array $globals): mixed {
    [$name, $path] = explode(':', $key, 2);
    $flat = $this->merger->flatten($globals[$name] ?? []);
    $global = $flat[$path] ?? NULL;

    if (is_bool($global)) {
      // The checkbox widget submits '1' or '0'.
      return $submitted === '1';
    }
    if (is_int($global)) {
      return (int) $submitted;
    }
    if (is_float($global)) {
      return (float) $submitted;
    }

    return $submitted;
  }

  /**
   * Builds the widget that matches the type of the setting.
   *
   * The widget is the typing: a boolean setting is a checkbox, a number is a
   * number field, so a value of the wrong type cannot be entered in the
   * first place. The stored value keeps the global value's type, so an
   * integer setting is stored and served as an integer, never as a string.
   */
  protected function valueWidget(string $key, mixed $global, mixed $override, bool $editable): array {
    $widget = [
      '#title' => $this->t('Value for @key', ['@key' => $key]),
      '#title_display' => 'invisible',
      '#disabled' => !$editable,
    ];

    if (is_bool($global)) {
      return $widget + [
        '#type' => 'checkbox',
        '#default_value' => (bool) ($override ?? $global),
      ];
    }
    if (is_int($global) || is_float($global)) {
      return $widget + [
        '#type' => 'number',
        '#step' => is_int($global) ? 1 : 'any',
        '#default_value' => $override ?? '',
      ];
    }

    return $widget + [
      '#type' => 'textfield',
      '#maxlength' => 512,
      '#default_value' => $override !== NULL ? $this->formatValue($override) : '',
      '#description' => $editable ? NULL : $this->t('Not editable here.'),
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
      return '';
    }
    if (is_array($value)) {
      return '[' . implode(', ', array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '...', $value)) . ']';
    }

    return (string) $value;
  }

}

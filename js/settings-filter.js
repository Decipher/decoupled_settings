/**
 * @file
 * Filters a settings table by the text typed into the filter field.
 */

(function (Drupal, once) {
  /**
   * Hides table rows that do not match the filter text.
   *
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.decoupledSettingsFilter = {
    attach(context) {
      once(
        'decoupled-settings-filter',
        '[data-decoupled-settings-filter]',
        context,
      ).forEach((input) => {
        const table = document.querySelector(
          input.dataset.decoupledSettingsFilter,
        );
        if (!table) {
          return;
        }
        input.addEventListener('input', () => {
          const query = input.value.toLowerCase();
          table.querySelectorAll('tbody tr').forEach((row) => {
            row.style.display = row.textContent.toLowerCase().includes(query)
              ? ''
              : 'none';
          });
        });
      });
    },
  };
})(Drupal, once);

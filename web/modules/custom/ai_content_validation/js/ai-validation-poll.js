/**
 * @file
 * Reloads the page once a queued AI validation has produced its report.
 *
 * The post-save validation runs in a shutdown function, after the response
 * is flushed, so the page the editor lands on still shows the previous
 * score. Rather than making the editor guess when to refresh, poll the
 * state endpoint and reload as soon as the report matches the content.
 */

(function (Drupal, drupalSettings, once) {
  'use strict';

  const INTERVAL = 2500;
  const ATTEMPTS = 48;

  /**
   * Builds the "Validating…" badge shown while the run is in flight.
   *
   * @return {Element}
   *   The badge element, ready to append to the page.
   */
  function badge() {
    const wrap = document.createElement('div');
    wrap.className = 'ai-validating';
    wrap.setAttribute('role', 'status');
    const box = document.createElement('div');
    box.className = 'ai-validating__box';
    const icon = document.createElement('span');
    icon.className = 'ai-validating__icon';
    icon.setAttribute('aria-hidden', 'true');
    const text = document.createElement('span');
    text.textContent = Drupal.t('Validating with AI…');
    const note = document.createElement('span');
    note.className = 'ai-validating__note';
    note.textContent = Drupal.t(
      'The quality score appears here as soon as the report lands.',
    );
    text.appendChild(note);
    box.appendChild(icon);
    box.appendChild(text);
    wrap.appendChild(box);
    return wrap;
  }

  Drupal.behaviors.aiValidationPoll = {
    attach() {
      const pending = drupalSettings.aiContentValidation?.pending;
      if (!pending) {
        return;
      }
      // once() on <body>: the poll belongs to the page, not to an element,
      // and an AJAX rebuild must not start a second one.
      once('ai-validation-poll', 'body').forEach((body) => {
        // Marks the wait so the score widgets can show it is in flight.
        body.classList.add('ai-validation-pending');
        const toast = badge();
        body.appendChild(toast);
        let left = ATTEMPTS;
        const stop = () => {
          body.classList.remove('ai-validation-pending');
          toast.remove();
        };
        const timer = setInterval(() => {
          left -= 1;
          if (left <= 0) {
            clearInterval(timer);
            stop();
            return;
          }
          fetch(pending.url, { headers: { Accept: 'application/json' } })
            .then((response) => (response.ok ? response.json() : null))
            .then((data) => {
              if (data && data.current) {
                clearInterval(timer);
                window.location.reload();
              }
            })
            .catch(() => {
              // A failed poll is not worth reporting: the next tick retries,
              // and the editor can always reload by hand.
            });
        }, INTERVAL);
      });
    },
  };
})(Drupal, drupalSettings, once);

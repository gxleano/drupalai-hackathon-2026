/**
 * @file
 * Per-field AI validation UI on the node edit form.
 *
 * Clicking a field's status dot opens a small popover anchored to the
 * dot with the finding and a "Fix with AI" action. The fix run ends in
 * a centered modal showing the suggestion as BEFORE / AFTER cards with
 * Apply / Reject / Edit actions that drive the hidden inline panel's
 * real form controls.
 */

(function (Drupal, drupalSettings, once) {
  'use strict';

  const OPEN_KEY = 'aiContentValidationOpenDrawer';

  /**
   * Creates an element with a class and optional text.
   *
   * @param {string} tag
   *   Tag name.
   * @param {string} className
   *   Class attribute.
   * @param {string} [text]
   *   Text content (always plain text, never markup).
   *
   * @return {Element}
   *   The element.
   */
  function el(tag, className, text) {
    const node = document.createElement(tag);
    node.className = className;
    if (text !== undefined) {
      node.textContent = text;
    }
    return node;
  }

  /**
   * Disables the dialog's actions and shows a progress note.
   *
   * @param {Element} content
   *   The dialog content element.
   * @param {string} message
   *   The progress message.
   */
  function lockDialog(content, message) {
    const progress = el('p', 'ai-nodeform-drawer__progress', message);
    // A live region: screen readers announce the wait when it starts.
    progress.setAttribute('role', 'status');
    content.prepend(progress);
    content
      .closest('.ui-dialog')
      ?.querySelectorAll('.ui-dialog-buttonpane button')
      .forEach((b) => {
        b.disabled = true;
      });
  }

  /**
   * Clones the suggestion diff as BEFORE / AFTER cards.
   *
   * The panel's word-level diff rows carry a leading "− " / "+ " text
   * marker; the modal replaces those with BEFORE / AFTER badges, so the
   * marker is stripped from the clone.
   *
   * @param {Element} panel
   *   The hidden inline suggestion panel.
   *
   * @return {Element|null}
   *   The cards wrapper, or null when the panel has no diff.
   */
  function buildDiffCards(panel) {
    const diff = panel.querySelector('.ai-review-suggestion__diff');
    if (!diff) {
      return null;
    }
    const wrap = el('div', 'ai-nodeform-drawer__diff');
    wrap.appendChild(diff.cloneNode(true));
    wrap.querySelectorAll('.ai-diff').forEach((row) => {
      const first = row.firstChild;
      if (
        first &&
        first.nodeType === Node.TEXT_NODE &&
        /^[−+] /.test(first.textContent)
      ) {
        first.textContent = first.textContent.slice(2);
      }
    });
    return wrap;
  }

  /**
   * Builds the modal's Edit section mirroring the panel's edit fields.
   *
   * The hidden panel already renders one prefilled textarea per editable
   * value (plain text, HTML source, or one per meta-tag key). The modal
   * mirrors each; apply() copies the values back before submitting.
   *
   * @param {Element} panel
   *   The hidden inline suggestion panel.
   *
   * @return {{section: Element, apply: Function}|null}
   *   The collapsed edit section and the copy-back callback, or null
   *   when the panel has no editable fields.
   */
  function buildEditSection(panel) {
    const sources = [...panel.querySelectorAll('textarea')];
    if (sources.length === 0) {
      return null;
    }
    const section = el('div', 'ai-nodeform-drawer__edit');
    section.hidden = true;
    const pairs = sources.map((source) => {
      // A body field's control is a text_format: its textarea holds HTML
      // source, and a CKEditor instance over it (built inside the hidden
      // panel) is the live value, so mirror that when it exists.
      const editor = findEditor(source);
      const html = editor !== null || source.closest('.js-text-format-wrapper');
      // A rich text mirror gets its own CKEditor, built from the same text
      // format as the source field, so the editor never edits raw HTML.
      const format =
        drupalSettings.editor?.formats?.[
          source.getAttribute('data-editor-active-text-format')
        ] ?? null;
      const wysiwyg = Boolean(html && format && Drupal.editors?.ckeditor5);
      const label = source.closest('.form-item')?.querySelector('label')
        ?.textContent;
      if (sources.length > 1 || html) {
        section.appendChild(
          el(
            'label',
            'ai-nodeform-drawer__edit-label',
            html && !wysiwyg
              ? Drupal.t('@label (HTML source)', {
                  '@label': label ? label.trim() : Drupal.t('Suggestion'),
                })
              : label
                ? label.trim()
                : Drupal.t('Suggestion'),
          ),
        );
      }
      const mirror = document.createElement('textarea');
      mirror.className = `ai-nodeform-drawer__edit-input${html && !wysiwyg ? ' ai-nodeform-drawer__edit-input--code' : ''}`;
      mirror.rows = sources.length > 1 ? 3 : 12;
      mirror.value = editor === null ? source.value : editor.getData();
      section.appendChild(mirror);
      return { source, mirror, format: wysiwyg ? format : null };
    });
    // Reads a mirror's live value: its own CKEditor instance when one was
    // attached, the plain textarea otherwise.
    const mirrorValue = (mirror) => {
      const id = mirror.getAttribute('data-ckeditor5-id');
      const instance =
        id === null ? null : Drupal.CKEditor5Instances?.get(id);
      return instance ? instance.getData() : mirror.value;
    };
    return {
      section,
      activate() {
        // CKEditor must not be built inside a hidden container (its toolbar
        // measures itself), so the instances attach on first reveal.
        pairs.forEach(({ mirror, format }) => {
          if (format && !mirror.hasAttribute('data-ckeditor5-id')) {
            Drupal.editors.ckeditor5.attach(mirror, format);
          }
        });
      },
      apply() {
        if (section.hidden) {
          return;
        }
        pairs.forEach(({ source, mirror }) => {
          const value = mirrorValue(mirror);
          source.value = value;
          // A CKEditor instance writes its own data over the textarea on
          // submit — keep it in sync so the edit survives.
          findEditor(source)?.setData(value);
        });
      },
    };
  }

  /**
   * Finds the CKEditor 5 instance attached to a textarea, if any.
   *
   * @param {Element} source
   *   The textarea.
   *
   * @return {?Object}
   *   The editor instance, or null when the textarea is plain.
   */
  function findEditor(source) {
    let found = null;
    // Drupal.CKEditor5Instances is a Map keyed by the element's data-ckeditor5-id.
    Drupal.CKEditor5Instances?.forEach((editor) => {
      if (editor.sourceElement === source) {
        found = editor;
      }
    });
    return found;
  }



  /**
   * Decodes HTML entities in a string built with Drupal.t().
   *
   * Drupal.t() escapes its placeholder values, but el() writes strings as
   * textContent — so an escaped "&amp;" would otherwise be shown verbatim.
   * Only ever called on strings this file composed itself.
   *
   * @param {string} text
   *   The escaped string.
   *
   * @return {string}
   *   The plain text.
   */
  function decode(text) {
    const holder = document.createElement('textarea');
    holder.innerHTML = text;
    return holder.value;
  }

  /**
   * Reads the guideline breakdown a passing dot carries, when present.
   *
   * @param {Element} dot
   *   The status dot.
   *
   * @return {{score: ?number, summary: string, items: Array}|null}
   *   The breakdown, or null when the field is flagged or unscored.
   */
  function parseDetails(dot) {
    if (!dot.dataset.aiDetails) {
      return null;
    }
    try {
      const data = JSON.parse(dot.dataset.aiDetails);
      return Array.isArray(data.items) && data.items.length ? data : null;
    } catch (e) {
      return null;
    }
  }

  /**
   * Reads the guideline numbers a field's own finding names.
   *
   * The finding is the only per-field verdict the report carries ("Guideline
   * 8: …"); the numeric `scores` map grades the whole document and must not
   * be attributed to a single field.
   *
   * @param {string} note
   *   The field's finding text.
   *
   * @return {Array<number>}
   *   The guideline numbers named, possibly empty.
   */
  function flaggedGuidelines(note) {
    const numbers = [];
    const pattern = /guidelines?\s*(?:nos?\.?\s*|#\s*)?((?:\d+\s*(?:,|and|&|\/)\s*)*\d+)/gi;
    let match = pattern.exec(note);
    while (match !== null) {
      match[1]
        .split(/[^0-9]+/)
        .filter(Boolean)
        .forEach((n) => numbers.push(Number(n)));
      match = pattern.exec(note);
    }
    return numbers;
  }

  /**
   * Builds the field's report: verdict, score and guideline breakdown.
   *
   * A passing field leads with the green verdict; a flagged one leads
   * with its finding. Both then show the same guideline list, so the
   * editor sees what is already satisfied either way.
   *
   * @param {Element} dot
   *   The status dot.
   * @param {{score: ?number, summary: string, items: Array}} details
   *   The guideline breakdown.
   *
   * @return {Element}
   *   The report body.
   */
  function buildReport(dot, details) {
    const ok = dot.dataset.aiState === 'pass';
    const note = dot.dataset.aiText || '';
    // The note names the guideline that failed on THIS field ("Guideline
    // 2: …"); the report's own scores are content-wide, so nothing from
    // them is shown as if it were the field's own verdict.
    const flagged = ok ? [] : flaggedGuidelines(note);
    const named = flagged.length
      ? details.items.find((item) => item.n === flagged[0])
      : null;
    const wrap = el(
      'div',
      `ai-nodeform-report ai-nodeform-report--${ok ? 'ok' : 'issue'}`,
    );
    const label = dot.dataset.aiLabel || Drupal.t('This field');

    if (details.stale) {
      wrap.appendChild(
        el(
          'p',
          'ai-nodeform-report__stale',
          Drupal.t(
            'The content has changed since this report — save to run the validation again.',
          ),
        ),
      );
    }

    const hero = el('div', 'ai-nodeform-report__hero');
    const heroText = el('div', 'ai-nodeform-report__hero-text');
    const line = el('div', 'ai-nodeform-report__line');
    line.appendChild(el('strong', 'ai-nodeform-report__verdict', label));
    line.appendChild(
      el(
        'span',
        'ai-nodeform-report__chip',
        ok ? Drupal.t('Passed') : Drupal.t('Needs attention'),
      ),
    );
    heroText.appendChild(line);
    heroText.appendChild(
      el(
        'span',
        'ai-nodeform-report__sub',
        ok
          ? Drupal.t(
              'This field meets all quality standards based on the EU content guidelines.',
            )
          : named
            ? decode(
                Drupal.t('Guideline @n (@name) needs attention on this field.', {
                  '@n': named.n,
                  '@name': named.name,
                }),
              )
            : Drupal.t('This field needs attention.'),
      ),
    );
    hero.appendChild(heroText);
    wrap.appendChild(hero);

    // No fix is offered when the content is too thin to rewrite: say why,
    // rather than leaving the editor to wonder where the button went.
    if (details.thin) {
      wrap.appendChild(
        el(
          'p',
          'ai-nodeform-report__note ai-nodeform-report__note--empty',
          Drupal.t(
            'There is not enough content here to improve — the AI would have to invent it. Write the content first, then save to re-run the validation.',
          ),
        ),
      );
    }

    // A flagged field with no rewrite on offer is one the AI must not
    // write: a taxonomy or media reference, where it would have to invent
    // the value rather than reword it.
    if (!ok && !details.thin && !dot.dataset.aiFix && !dot.dataset.aiSuggestion) {
      wrap.appendChild(
        el(
          'p',
          'ai-nodeform-report__note ai-nodeform-report__note--empty',
          Drupal.t(
            'This field is reviewed but never rewritten by the AI — it references other content, so the right value is yours to choose. Edit the field to fix it.',
          ),
        ),
      );
    }

    // The note only earns its space when there is something to fix; the
    // verdict card above already says a passing field is fine.
    if (!ok && note) {
      wrap.appendChild(
        el(
          'p',
          `ai-nodeform-report__note ai-nodeform-report__note--${ok ? 'ok' : 'issue'}`,
          note,
        ),
      );
    }

    wrap.appendChild(
      el('h3', 'ai-nodeform-report__heading', Drupal.t('Guideline breakdown')),
    );
    const list = el('ul', 'ai-nodeform-report__list');
    details.items.forEach((item) => {
      // Per-field verdict: only the guidelines this field's own note names
      // are flagged. The report's `scores` map grades the whole document,
      // so reusing it here would mark a guideline weak on every field.
      const weak = flagged.includes(item.n);
      const row = el(
        'li',
        `ai-nodeform-report__item ai-nodeform-report__item--${weak ? 'weak' : 'ok'}`,
      );
      const text = el('span', 'ai-nodeform-report__item-text');
      text.appendChild(
        el('span', 'ai-nodeform-report__item-name', `${item.n}. ${item.name}`),
      );
      if (item.desc) {
        text.appendChild(
          el('span', 'ai-nodeform-report__item-desc', item.desc),
        );
      }
      row.appendChild(text);
      row.appendChild(
        el(
          'span',
          'ai-nodeform-report__item-value',
          weak ? Drupal.t('Flagged here') : Drupal.t('Pass'),
        ),
      );
      list.appendChild(row);
    });
    wrap.appendChild(list);

    return wrap;
  }

  /**
   * Moves the editor to the field the status dot belongs to.
   *
   * @param {Element} dot
   *   The status dot.
   */
  function focusField(dot) {
    const wrapper = dot.closest('.ai-nodeform-field');
    if (!wrapper) {
      return;
    }
    // A field grouped into a sidebar details (meta tags) must be open
    // before its widget can take focus.
    if (wrapper.tagName === 'DETAILS') {
      wrapper.open = true;
    }
    const target = wrapper.querySelector(
      'input:not([type="hidden"]):not([type="submit"]), textarea, select, [contenteditable="true"]',
    );
    wrapper.scrollIntoView({ block: 'center', behavior: 'smooth' });
    target?.focus({ preventScroll: true });
  }

  /**
   * Opens the small finding popover anchored to a status dot.
   *
   * @param {Element} dot
   *   The clicked status dot.
   */
  function openFinding(dot) {
    const content = el('div', 'ai-nodeform-drawer');
    const details = parseDetails(dot);

    if (details) {
      content.appendChild(buildReport(dot, details));
    } else {
      content.appendChild(
        el('p', 'ai-nodeform-popover__text', dot.dataset.aiText || ''),
      );
    }

    let dialog;
    // A compliant field needs no footer at all: there is nothing to act on
    // and the title bar's X closes the dialog. Otherwise: back to the field
    // on the left, the AI action on the right.
    // Edit field belongs to any flagged field — the editor fixes it by
    // hand when no AI rewrite is offered. A passing field needs nothing.
    const buttons = dot.dataset.aiState === 'issue'
      ? [
          {
            text: Drupal.t('Edit field'),
            class: 'button ai-btn--field',
            click() {
              dialog.close();
              focusField(dot);
            },
          },
        ]
      : [];
    if (dot.dataset.aiSuggestion) {
      buttons.push({
        text: Drupal.t('Review suggestion'),
        class: 'button button--primary',
        click() {
          dialog.close();
          openSuggestion(dot);
        },
      });
    } else if (dot.dataset.aiFix) {
      buttons.push({
        text: Drupal.t('Fix with AI'),
        class: 'button button--primary ai-btn--fix',
        click() {
          const submit = document.querySelector(
            `input[name="${dot.dataset.aiFix}"], button[name="${dot.dataset.aiFix}"]`,
          );
          if (submit) {
            // Reopen the suggestion modal on the rebuilt page, where the
            // fresh suggestion will be waiting.
            try {
              sessionStorage.setItem(OPEN_KEY, dot.dataset.aiFix.split(':')[2]);
            } catch (e) {
              // Storage unavailable: the editor reopens the dot manually.
            }
            lockDialog(
              content,
              Drupal.t(
                'Rewriting the field based on its findings… this takes about a minute.',
              ),
            );
            submit.click();
          }
        },
      });
    }

    dialog = Drupal.dialog(content, {
      // The field name already heads the card inside the dialog.
      title: Drupal.t('AI validation'),
      classes: {
        'ui-dialog': details
          ? 'ai-nodeform-popover ai-nodeform-popover--report'
          : 'ai-nodeform-popover',
      },
      width: details ? 520 : 380,
      // A one-line finding stays anchored to its dot; the full report is
      // too tall for that — it centers like a modal and scrolls inside.
      position: details
        ? { my: 'center', at: 'center', of: window }
        : { my: 'right top', at: 'right bottom+8', of: dot },
      buttons,
    });
    dialog.showModal();
    closeOnBackdrop(dialog);
  }

  /**
   * Closes a modal dialog when its backdrop is clicked.
   *
   * jQuery UI's overlay swallows the click without closing; the dot dialogs
   * are read-mostly, so a click outside should dismiss them like Escape.
   *
   * @param {object} dialog
   *   The Drupal.dialog instance that was just opened.
   */
  function closeOnBackdrop(dialog) {
    const overlays = document.querySelectorAll('.ui-widget-overlay');
    const overlay = overlays[overlays.length - 1];
    overlay?.addEventListener('click', () => dialog.close(), { once: true });
  }

  /**
   * Opens the centered suggestion modal for a status dot.
   *
   * @param {Element} dot
   *   The status dot whose field has a pending suggestion.
   */
  function openSuggestion(dot) {
    const panel = document.querySelector(
      `[data-ai-panel="${dot.dataset.aiSuggestion}"]`,
    );
    if (!panel) {
      return;
    }
    const content = el('div', 'ai-nodeform-drawer');

    const card = el('div', 'ai-nodeform-drawer__card');
    card.appendChild(
      el('strong', 'ai-nodeform-drawer__card-title', dot.dataset.aiLabel || ''),
    );
    card.appendChild(
      el('p', 'ai-nodeform-drawer__card-text', dot.dataset.aiText || ''),
    );
    content.appendChild(card);

    content.appendChild(
      el('h3', 'ai-nodeform-drawer__heading', Drupal.t('Suggested fix')),
    );
    const reason = panel.querySelector('.ai-review-suggestion__reason');
    if (reason) {
      content.appendChild(
        el('p', 'ai-nodeform-drawer__reason', reason.textContent),
      );
    }
    const cards = buildDiffCards(panel);
    if (cards) {
      content.appendChild(cards);
    }
    const edit = buildEditSection(panel);
    if (edit) {
      content.appendChild(edit.section);
    }
    content.appendChild(
      el(
        'p',
        'ai-nodeform-drawer__hint',
        Drupal.t(
          'Applying saves a new revision — unsaved changes on this form are discarded.',
        ),
      ),
    );

    let dialog;
    const buttons = [
      {
        text: Drupal.t('Apply'),
        class: 'button button--primary ai-btn--apply',
        click() {
          edit?.apply();
          lockDialog(content, Drupal.t('Applying the change…'));
          panel.querySelector('[name^="applyfieldsug:"]')?.click();
        },
      },
      {
        text: Drupal.t('Reject'),
        class: 'button ai-btn--reject',
        click() {
          lockDialog(content, Drupal.t('Dismissing the suggestion…'));
          panel.querySelector('[name^="ignorefieldsug:"]')?.click();
        },
      },
    ];
    if (edit) {
      buttons.push({
        text: Drupal.t('Edit'),
        class: 'button ai-btn--edit',
        click() {
          edit.section.hidden = !edit.section.hidden;
          this.setAttribute('aria-expanded', String(!edit.section.hidden));
          if (!edit.section.hidden) {
            edit.activate();
            edit.section.querySelector('textarea')?.focus();
          }
        },
      });
    }
    buttons.push({
      text: Drupal.t('Exit'),
      class: 'button',
      click() {
        dialog.close();
      },
    });

    dialog = Drupal.dialog(content, {
      title: dot.dataset.aiTitle || Drupal.t('AI Validation Assistant'),
      open() {
        this.closest('.ui-dialog')
          ?.querySelector('.ai-btn--edit')
          ?.setAttribute('aria-expanded', 'false');
      },
      classes: { 'ui-dialog': 'ai-nodeform-modal' },
      width: 640,
      // Drupal's dialog defaults are inherited from whatever opened it —
      // the finding popover anchors to its dot, so the modal must state
      // its own centering instead of drifting to the left.
      position: { my: 'center', at: 'center', of: window },
      buttons,
    });
    dialog.showModal();
    closeOnBackdrop(dialog);
  }

  Drupal.behaviors.aiNodeFormStatus = {
    attach(context) {
      // A dot rendered inside a collapsed sidebar details (meta tags)
      // would be invisible — move it into the always-visible summary.
      once('ai-status-summary', '.ai-nodeform-field--group', context).forEach(
        (details) => {
          const status = details.querySelector('.ai-nodeform-status');
          const summary = details.querySelector('summary');
          if (status && summary) {
            summary.appendChild(status);
          }
        },
      );
      once('ai-status-dialog', '.ai-nodeform-status__dot', context).forEach(
        (dot) => {
          dot.addEventListener('click', (event) => {
            event.preventDefault();
            openFinding(dot);
          });
        },
      );
      // A "Fix with AI" run just finished: open the suggestion modal for
      // its field right away instead of making the editor re-click.
      let pendingField = null;
      try {
        pendingField = sessionStorage.getItem(OPEN_KEY);
      } catch (e) {
        pendingField = null;
      }
      if (pendingField) {
        const dot = document.querySelector(
          `.ai-nodeform-status__dot[data-ai-suggestion="${pendingField}"]`,
        );
        if (dot) {
          try {
            sessionStorage.removeItem(OPEN_KEY);
          } catch (e) {
            // Ignore.
          }
          openSuggestion(dot);
        }
      }
    },
  };
})(Drupal, drupalSettings, once);

/**
 * @file
 * Attaches behavior for the Editor module.
 */

(function ($, Drupal) {

"use strict";

/**
 * Initialize an empty object for editors to place their attachment code.
 */
Drupal.editors = {};

/**
 * Enables editors on text_format elements.
 */
Drupal.behaviors.editor = {
  attach: function (context, settings) {
    // If there are no editor settings, there are no editors to enable.
    if (!settings.editor) {
      return;
    }

    var $context = $(context);
    var behavior = this;
    $context.find('.editor').once('editor', function () {
      var $this = $(this);
      var activeFormatID = $this.val();
      var field = behavior.findFieldForFormatSelector($this);

      // Directly attach this editor, if the text format is enabled.
      if (settings.editor.formats[activeFormatID]) {
        Drupal.editorAttach(field, settings.editor.formats[activeFormatID]);
      }

      // Attach onChange handler to text format selector element.
      if ($this.is('select')) {
        $this.on('change.editorAttach', function () {
          var newFormatID = $this.val();

          // Prevent double-attaching if the change event is triggered manually.
          if (newFormatID === activeFormatID) {
            return;
          }

          // Detach the current editor (if any) and attach a new editor.
          if (settings.editor.formats[activeFormatID]) {
            Drupal.editorDetach(field, settings.editor.formats[activeFormatID]);
          }
          activeFormatID = newFormatID;
          if (settings.editor.formats[activeFormatID]) {
            Drupal.editorAttach(field, settings.editor.formats[activeFormatID]);
          }
        });
      }
      // Detach any editor when the containing form is submitted.
      $this.parents('form').submit(function (event) {
        // Do not detach if the event was canceled.
        if (event.isDefaultPrevented()) {
          return;
        }
        Drupal.editorDetach(field, settings.editor.formats[activeFormatID], 'serialize');
      });
    });
  },

  detach: function (context, settings, trigger) {
    var editors;
    // The 'serialize' trigger indicates that we should simply update the
    // underlying element with the new text, without destroying the editor.
    if (trigger === 'serialize') {
      // Removing the editor-processed class guarantees that the editor will
      // be reattached. Only do this if we're planning to destroy the editor.
      editors = $(context).find('.editor-processed');
    }
    else {
      editors = $(context).find('.editor').removeOnce('editor');
    }

    var behavior = this;
    editors.each(function () {
      var $this = $(this);
      var activeFormatID = $this.val();
      var field = behavior.findFieldForFormatSelector($this);

      Drupal.editorDetach(field, settings.editor.formats[activeFormatID], trigger);
    });
  },

  findFieldForFormatSelector: function ($formatSelector) {
    var field_id = $formatSelector.attr('data-editor-for');
    return $('#' + field_id).get(0);
  }
};

Drupal.editorAttach = function (field, format) {
  if (format.editor) {
    Drupal.editors[format.editor].attach(field, format);
  }
};

Drupal.editorDetach = function (field, format, trigger) {
  if (format.editor) {
    Drupal.editors[format.editor].detach(field, format, trigger);
  }
};

})(jQuery, Drupal);

(function ($, Drupal) {
    Drupal.AjaxCommands.prototype.strawberryfield_codemirror = function (ajax, response, status) {
        if (!window.CodeMirror) {
            return;
        }

        $editors = $(response.selector).find('.CodeMirror');

        if (response.hasOwnProperty('content') &&
            $editors.length > 0 ) {
            console.log('we have content');
            $editors[0].CodeMirror.setValue(response.content);
        }
    };

})(jQuery, Drupal);

/**
 * Reading Time & Progress Pro — Admin JS
 * Live preview for progress bar settings.
 */
(function ($) {
    'use strict';

    $(function () {
        var $bar     = $('#rpp-preview-bar');
        var $track   = $('#rpp-preview-track');
        var $color   = $('#rpp-bar-color');
        var $colorHex = $('#rpp-bar-color-hex');
        var $height  = $('#rpp-bar-height');
        var $heightVal = $('#rpp-bar-height-val');
        var $posTop  = $('#rpp-bar-pos-top');
        var $posBot  = $('#rpp-bar-pos-bottom');
        var $trackCb = $('#rpp-bar-track');
        var $enabled = $('#rpp-bar-enabled');

        function updatePreview() {
            var color   = $color.val();
            var height  = $height.val();
            var isTop   = $posTop.is(':checked');
            var showTrack = $trackCb.is(':checked');
            var enabled = $enabled.is(':checked');

            // Update bar
            $bar.css({
                'background': color,
                'height': height + 'px',
                'top': isTop ? '0' : 'auto',
                'bottom': isTop ? 'auto' : '0'
            });

            // Update track
            $track.css({
                'height': height + 'px',
                'top': isTop ? '0' : 'auto',
                'bottom': isTop ? 'auto' : '0',
                'display': showTrack ? 'block' : 'none'
            });

            // Show/hide
            if (enabled) {
                $bar.show();
            } else {
                $bar.hide();
            }

            // Update display values
            $colorHex.text(color);
            $heightVal.text(height + 'px');
        }

        // Bind events
        $color.on('input', updatePreview);
        $height.on('input', updatePreview);
        $posTop.on('change', updatePreview);
        $posBot.on('change', updatePreview);
        $trackCb.on('change', updatePreview);
        $enabled.on('change', updatePreview);

        // Animate the preview bar width
        var direction = 1;
        var width = 65;
        setInterval(function () {
            width += direction * 2;
            if (width >= 95) direction = -1;
            if (width <= 10) direction = 1;
            $bar.css('width', width + '%');
        }, 100);

        // Initial update
        updatePreview();
    });
})(jQuery);

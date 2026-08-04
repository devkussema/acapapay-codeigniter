<?php
/**
 * @var string      $checkoutUrl
 * @var string|null $height
 * @var string|null $width
 */
$height = $height ?? '700px';
$width  = $width ?? '100%';
?>
<div class="acapapay-iframe-container" style="position: relative; width: <?= esc($width, 'attr') ?>; height: <?= esc($height, 'attr') ?>;">
    <iframe
        id="acapapay-checkout-frame"
        src="<?= esc($checkoutUrl, 'attr') ?>"
        width="100%"
        height="100%"
        frameborder="0"
        allowpaymentrequest
        style="border: none; border-radius: 8px; box-shadow: 0 4px 6px -1px rgba(0,0,0,.1), 0 2px 4px -1px rgba(0,0,0,.06);"
    ></iframe>
    <script>
        window.addEventListener('message', function (event) {
            var data = event.data;
            if (data && typeof data === 'object') {
                if (data.event === 'acapapay.success') {
                    window.dispatchEvent(new CustomEvent('acapapay-success', { detail: data }));
                }
                if (data.event === 'acapapay.cancel') {
                    window.dispatchEvent(new CustomEvent('acapapay-cancel', { detail: data }));
                }
            }
        });
    </script>
</div>

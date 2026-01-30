{{-- 
    QR Scanner Modal Component
    This component provides a consistent, styled modal for QR code scanning.
    It should be placed on a page that also includes the necessary JavaScript to control it.
--}}
<div id="qr-scanner-modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.6);">
    <div style="background-color: #1f2937; margin: 10% auto; padding: 20px; border: 1px solid #888; width: 80%; max-width: 500px; position: relative; border-radius: 0.5rem; color: white;">
        <span id="close-qr-modal" style="color: #aaa; float: right; font-size: 36px; font-weight: bold; position: absolute; top: -15px; right: 0px; cursor: pointer;">&times;</span>
        <h3 style="font-size: 1.5rem; font-weight: bold; margin-bottom: 1rem; text-align: center;">Scan Document QR Code</h3>
        <div id="qr-reader" style="width: 100%; border-radius: 0.5rem; overflow: hidden;"></div>
    </div>
</div>

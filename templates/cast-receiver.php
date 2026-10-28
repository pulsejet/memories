<?php
/** @var array $_ */
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Memories</title>
  <style>
    html, body {
      margin: 0;
      width: 100%;
      height: 100%;
      overflow: hidden;
      background: #000;
      color: #fff;
      font-family: 'Google Sans', Roboto, sans-serif;
    }

    .layer {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
      object-fit: contain;
      opacity: 0;
      transition: opacity 0.8s ease-in-out;
    }

    .layer.visible {
      opacity: 1;
    }

    #idle {
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 64px;
      font-weight: 300;
      letter-spacing: 2px;
    }

    #info {
      position: absolute;
      left: 64px;
      bottom: 48px;
      text-shadow: 0 0 12px rgba(0, 0, 0, 0.9), 0 0 2px rgba(0, 0, 0, 0.9);
      opacity: 0;
      transition: opacity 0.8s ease-in-out;
    }

    #info.visible {
      opacity: 1;
    }

    #info-date {
      font-size: 32px;
    }

    #info-place {
      font-size: 22px;
      opacity: 0.85;
    }
  </style>
</head>
<body>
  <img id="img-a" class="layer" alt="">
  <img id="img-b" class="layer" alt="">
  <video id="video" class="layer" playsinline></video>
  <div id="idle" class="layer visible">Memories</div>
  <div id="info">
    <div id="info-date"></div>
    <div id="info-place"></div>
  </div>

  <script nonce="<?php p($_['cspNonce']); ?>" src="https://www.gstatic.com/cast/sdk/libs/caf_receiver/v3/cast_receiver_framework.js"></script>
  <script nonce="<?php p($_['cspNonce']); ?>" src="<?php p($_['script']); ?>"></script>
</body>
</html>

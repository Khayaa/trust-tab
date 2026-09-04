---
paths:
  - 'resources/js/**'
---

# Js

## Keep barcode JS out of Livewire views
Barcode scanning lives in resources/js/barcode-scanner.js (Barcode Detection API or Mini App camera, plus a typed barcode fallback). Do not scatter getUserMedia through Blade. Same isolation as momo-bridge.js.

## Scanner looks up, never deducts
Camera and Mini App scan live only in barcode-scanner.js. Products typed Look up calls findByBarcode: a hit opens that product for edit; a miss keeps the barcode so it can be added. Never decrement stock on scan.

## Scan calls data-barcode-action
barcode-scanner.js reads data-barcode-action on the Scan trigger and Livewire.call()s that method with the code. Products uses findByBarcode (opens edit). The till uses addByBarcode (adds a line). Default is findByBarcode. Never decrement stock on scan.

## iOS scan uses ZXing after the camera opens
iOS Chrome and Safari have getUserMedia but no BarcodeDetector. Open the camera on the Scan tap first (user gesture), then decode with the native detector when it exists, otherwise ZXing from @zxing/browser. Do not refuse Scan only because BarcodeDetector is missing. Typed lookup stays the fallback if the camera is denied.

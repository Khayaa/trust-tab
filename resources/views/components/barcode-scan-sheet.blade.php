@teleport('body')
    <div id="barcode-scan-overlay" hidden wire:ignore
         class="fixed inset-0 z-50 flex flex-col bg-momo-950/90 p-4"
         role="dialog"
         aria-modal="true"
         aria-labelledby="scan-title">
        <div class="flex items-center justify-between gap-3 pt-[env(safe-area-inset-top)]">
            <p id="scan-title" class="text-sm font-bold text-white">Point at the barcode</p>
            <button type="button" data-barcode-cancel
                    class="rounded-lg px-3 py-2 text-sm font-semibold text-white hover:bg-white/10">
                Cancel
            </button>
        </div>
        <video data-barcode-video playsinline webkit-playsinline muted
               class="mt-4 min-h-0 w-full flex-1 rounded-2xl bg-black object-cover"></video>
        <p class="mt-3 text-center text-xs text-momo-100">Hold the code in the frame. Stock stays on the shelf until checkout.</p>
    </div>
@endteleport

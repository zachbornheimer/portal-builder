<script>
  import { Dialog } from "bits-ui";
  import { previewApi } from './preview-api.js';

  const DEFAULT_TITLE = 'Confirm this file';
  const CLOSE_LABEL = 'Close';
  const KIND_AUDIO = 'audio';
  const KIND_IMAGE = 'image';

  let isOpen = $state(false);
  let url = $state('');
  let kind = $state('iframe');
  let title = $state(DEFAULT_TITLE);
  let returnFocus = $state(/** @type {HTMLElement | null} */ (null));
  let onClose = $state(/** @type {null | (() => void)} */ (null));

  /**
   * @param {{ url?: string, kind?: string, title?: string, returnFocus?: HTMLElement | null, onClose?: () => void }} [options]
   */
  function open(options) {
    const opts = options || {};
    url = opts.url || '';
    kind = opts.kind || 'iframe';
    title = opts.title || DEFAULT_TITLE;
    returnFocus = opts.returnFocus || null;
    onClose = typeof opts.onClose === 'function' ? opts.onClose : null;
    isOpen = true;
  }

  /**
   * @param {Event} event
   */
  function handleCloseAutoFocus(event) {
    if (returnFocus && typeof returnFocus.focus === 'function') {
      event.preventDefault();
      returnFocus.focus();
    }
    if (onClose) {
      onClose();
    }
  }

  previewApi.open = open;
</script>

<Dialog.Root bind:open={isOpen}>
  <Dialog.Portal>
    <Dialog.Overlay class="dg-file-preview-backdrop" />
    <Dialog.Content class="dg-file-preview-panel" onCloseAutoFocus={handleCloseAutoFocus}>
      <div class="dg-file-preview-bar">
        <Dialog.Title class="dg-file-preview-title">{title}</Dialog.Title>
        <Dialog.Close class="dg-file-preview-close">{CLOSE_LABEL}</Dialog.Close>
      </div>
      <div class="dg-file-preview-body">
        {#if url && kind === KIND_AUDIO}
          <audio class="dg-file-preview-media" controls src={url}></audio>
        {:else if url && kind === KIND_IMAGE}
          <img class="dg-file-preview-media" alt={title} src={url} />
        {:else if url}
          <iframe class="dg-file-preview-media" {title} src={url}></iframe>
        {/if}
      </div>
    </Dialog.Content>
  </Dialog.Portal>
</Dialog.Root>

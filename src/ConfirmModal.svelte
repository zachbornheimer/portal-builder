<script>
  import { Dialog } from "bits-ui";
  import { createEventDispatcher } from 'svelte';
  import { shouldDispatchCancel, confirmedForOpen } from './confirm-dismiss.js';

  /**
   * @typedef {Object} Props
   * @property {boolean} [isOpen]
   * @property {string} [title]
   * @property {string} [message]
   * @property {string} [confirmText]
   * @property {string} [cancelText]
   * @property {string} [confirmButtonClass]
   * @property {string} [cancelButtonClass]
   */

  /** @type {Props} */
  let {
    isOpen = $bindable(false),
    title = 'Confirm Action',
    message = 'Are you sure you want to proceed?',
    confirmText = 'Confirm',
    cancelText = 'Cancel',
    confirmButtonClass = 'bg-red-600 hover:bg-red-500 text-white',
    cancelButtonClass = 'bg-white hover:bg-gray-50 text-gray-900 ring-1 ring-inset ring-gray-300'
  } = $props();

  const dispatch = createEventDispatcher();

  let confirmed = $state(false);

  $effect.pre(() => {
    if (isOpen) {
      confirmed = confirmedForOpen(isOpen, true);
    }
  });

  function handleConfirm() {
    confirmed = true;
    dispatch('confirm');
    isOpen = false;
  }

  function handleOpenChange(next) {
    if (shouldDispatchCancel(next, confirmed)) {
      dispatch('cancel');
    }
  }
</script>

<Dialog.Root bind:open={isOpen} onOpenChange={handleOpenChange}>
  <Dialog.Portal>
    <Dialog.Overlay class="fixed inset-0 z-50 bg-gray-500/75 transition-opacity" />
    <Dialog.Content class="fixed left-1/2 top-1/2 z-50 w-[calc(100%-2rem)] max-w-lg -translate-x-1/2 -translate-y-1/2 outline-none">
      <div class="p-4 text-center sm:p-0 sm:text-left">
        <div class="relative overflow-hidden rounded-lg bg-white text-left shadow-xl">
          <div class="bg-white px-4 pb-4 pt-5 sm:p-6 sm:pb-4">
            <div class="sm:flex sm:items-start">
              <div class="mx-auto flex size-12 shrink-0 items-center justify-center rounded-full bg-red-100 sm:mx-0 sm:size-10">
                <svg class="size-6 text-red-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                </svg>
              </div>
              <div class="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left">
                <Dialog.Title class="text-base font-semibold text-gray-900">
                  {title}
                </Dialog.Title>
                <div class="mt-2">
                  <Dialog.Description class="text-sm text-gray-500">
                    {message}
                  </Dialog.Description>
                </div>
              </div>
            </div>
          </div>
          <div class="bg-gray-50 px-4 py-3 sm:flex sm:flex-row-reverse sm:px-6">
            <button
              type="button"
              class="inline-flex w-full justify-center rounded-md px-3 py-2 text-sm font-semibold shadow-sm sm:ml-3 sm:w-auto {confirmButtonClass}"
              onclick={handleConfirm}
            >
              {confirmText}
            </button>
            <Dialog.Close
              class="mt-3 inline-flex w-full justify-center rounded-md px-3 py-2 text-sm font-semibold shadow-sm sm:mt-0 sm:w-auto {cancelButtonClass}"
            >
              {cancelText}
            </Dialog.Close>
          </div>
        </div>
      </div>
    </Dialog.Content>
  </Dialog.Portal>
</Dialog.Root>

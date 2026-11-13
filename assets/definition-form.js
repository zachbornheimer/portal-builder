/**
 * Public definition form — file enclosure + open-to-confirm + branch paths.
 *
 * After a file is chosen: stage bytes (XHR progress → anonymize spinner →
 * retained server name). Confirm proves the file bytes are readable, then
 * paints the file in a page dialog. Opened means proveReadable succeeded.
 *
 * Branch radios show only the selected path’s `.dg-branch-children` and disable
 * hidden controls so HTML5 `required` cannot block submit.
 */
;(function () {
  'use strict'

  var KB = 1024
  var MB = 1024 * 1024
  var STATUS_WORKING = 'Processing'
  var STATUS_SUBMITTING = 'Submitting'
  var STAGE_FAIL = 'Upload failed. Remove the upload and try again.'
  var FILE_OPEN_FAIL = 'This file didn’t open. Remove the upload and try another.'
  var PREVIEW_TITLE = 'Confirm this file'
  var PREVIEW_CLOSE = 'Close'
  var PREVIEW_DIALOG_ID = 'dg-file-preview'
  var PREVIEW_TITLE_ID = 'dg-file-preview-title'
  var previewReturnFocus = null

  /**
   * DOM-free visibility plan (mirrors src/wizard/branchVisibility.js).
   * @param {string} selectedId
   * @param {string[]} optionIds
   * @returns {{ id: string, visible: boolean }[]}
   */
  function visibleBranchOptions(selectedId, optionIds) {
    var selected = selectedId == null ? '' : String(selectedId)
    var out = []
    for (var i = 0; i < optionIds.length; i += 1) {
      var id = optionIds[i]
      out.push({ id: id, visible: id === selected && selected !== '' })
    }
    return out
  }

  function formatSize(bytes) {
    if (bytes < KB) {
      return String(bytes) + ' B'
    }
    if (bytes < MB) {
      return trimFixed(bytes / KB) + ' KB'
    }
    return trimFixed(bytes / MB) + ' MB'
  }

  function trimFixed(value) {
    return value.toFixed(1).replace(/\.0$/, '')
  }

  function publicConfig() {
    return window.dgPublicForm && typeof window.dgPublicForm === 'object' ? window.dgPublicForm : {}
  }

  function stageUrl() {
    var cfg = publicConfig()
    return cfg.stageUrl ? String(cfg.stageUrl) : ''
  }

  function forceNewTabLinks(root) {
    var links = (root || document).querySelectorAll('a[href]')
    for (var i = 0; i < links.length; i += 1) {
      var href = links[i].getAttribute('href') || ''
      if (!href || href.charAt(0) === '#') {
        continue
      }
      if (links[i].closest('.dg-access')) {
        continue
      }
      links[i].target = '_blank'
      var rel = links[i].getAttribute('rel') || ''
      if (rel.indexOf('noopener') === -1) {
        links[i].setAttribute('rel', (rel + ' noopener noreferrer').trim())
      }
    }
  }

  function previewKind(card, url) {
    var type = ((card && card.getAttribute('data-dg-field-type')) || '').toLowerCase()
    var path = String(url || '')
      .split('?')[0]
      .toLowerCase()
    if (type === 'audio' || type === 'recording_file' || /\.(mp3|wav|m4a|ogg|aac)$/.test(path)) {
      return 'audio'
    }
    if (type === 'image' || /\.(png|jpe?g|gif|webp)$/.test(path)) {
      return 'image'
    }
    return 'iframe'
  }

  function previewFocusable(dialog) {
    return dialog.querySelectorAll(
      'button:not([disabled]), [href], input, select, textarea, iframe, audio, [tabindex]:not([tabindex="-1"])',
    )
  }

  function closePreviewDialog() {
    var dialog = document.getElementById(PREVIEW_DIALOG_ID)
    if (!dialog) {
      return
    }
    var body = dialog.querySelector('[data-dg-preview-body]')
    if (body) {
      body.textContent = ''
    }
    dialog.hidden = true
    dialog.setAttribute('hidden', '')
    if (previewReturnFocus && typeof previewReturnFocus.focus === 'function') {
      previewReturnFocus.focus()
    }
    previewReturnFocus = null
  }

  function trapPreviewFocus(dialog, event) {
    if (event.key === 'Escape') {
      event.preventDefault()
      closePreviewDialog()
      return
    }
    if (event.key !== 'Tab') {
      return
    }
    var nodes = previewFocusable(dialog)
    if (!nodes.length) {
      event.preventDefault()
      return
    }
    var first = nodes[0]
    var last = nodes[nodes.length - 1]
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault()
      last.focus()
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault()
      first.focus()
    }
  }

  function ensurePreviewDialog() {
    var existing = document.getElementById(PREVIEW_DIALOG_ID)
    if (existing) {
      return existing
    }
    var dialog = document.createElement('div')
    dialog.id = PREVIEW_DIALOG_ID
    dialog.className = 'dg-file-preview'
    dialog.setAttribute('role', 'dialog')
    dialog.setAttribute('aria-modal', 'true')
    // role="dialog" — in-page confirm; a tab cannot callback.
    dialog.setAttribute('aria-labelledby', PREVIEW_TITLE_ID)
    dialog.hidden = true
    dialog.setAttribute('hidden', '')

    var backdrop = document.createElement('div')
    backdrop.className = 'dg-file-preview-backdrop'
    backdrop.setAttribute('data-dg-preview-dismiss', '')

    var panel = document.createElement('div')
    panel.className = 'dg-file-preview-panel'

    var bar = document.createElement('div')
    bar.className = 'dg-file-preview-bar'

    var title = document.createElement('h2')
    title.id = PREVIEW_TITLE_ID
    title.className = 'dg-file-preview-title'
    title.textContent = PREVIEW_TITLE

    var closeBtn = document.createElement('button')
    closeBtn.type = 'button'
    closeBtn.className = 'dg-file-preview-close'
    closeBtn.setAttribute('data-dg-preview-dismiss', '')
    closeBtn.textContent = PREVIEW_CLOSE

    var body = document.createElement('div')
    body.className = 'dg-file-preview-body'
    body.setAttribute('data-dg-preview-body', '')

    bar.appendChild(title)
    bar.appendChild(closeBtn)
    panel.appendChild(bar)
    panel.appendChild(body)
    dialog.appendChild(backdrop)
    dialog.appendChild(panel)
    document.body.appendChild(dialog)

    dialog.addEventListener('click', function (event) {
      if (
        event.target &&
        event.target.getAttribute &&
        event.target.hasAttribute('data-dg-preview-dismiss')
      ) {
        closePreviewDialog()
      }
    })
    dialog.addEventListener('keydown', function (event) {
      trapPreviewFocus(dialog, event)
    })
    return dialog
  }

  function paintPreview(card, url) {
    var dialog = ensurePreviewDialog()
    var body = dialog.querySelector('[data-dg-preview-body]')
    body.textContent = ''
    var kind = previewKind(card, url)
    var media
    if (kind === 'audio') {
      media = document.createElement('audio')
      media.controls = true
    } else if (kind === 'image') {
      media = document.createElement('img')
      media.alt = PREVIEW_TITLE
    } else {
      media = document.createElement('iframe')
      media.title = PREVIEW_TITLE
    }
    media.className = 'dg-file-preview-media'
    media.src = url
    body.appendChild(media)
    dialog.hidden = false
    dialog.removeAttribute('hidden')
    var closeBtn = dialog.querySelector('.dg-file-preview-close')
    if (closeBtn) {
      closeBtn.focus()
    }
  }

  function openConfirmedPreview(card, url, onReady, onError) {
    var preview = globalThis.FilePreview
    if (!preview || typeof preview.proveReadable !== 'function') {
      onError()
      return
    }
    preview
      .proveReadable({
        url: url,
        kind: previewKind(card, url),
      })
      .then(function () {
        paintPreview(card, url)
        onReady()
      }, onError)
  }

  function bindFileCard(card) {
    var input = card.querySelector('input[type="file"]')
    if (!input) {
      return
    }
    var nameEl = card.querySelector('[data-dg-file-name]')
    var sizeEl = card.querySelector('[data-dg-file-size]')
    var originalEl = card.querySelector('[data-dg-file-original]')
    var sepEl = card.querySelector('[data-dg-file-sep]')
    var openBtn = card.querySelector('[data-dg-file-open]')
    var swapBtn = card.querySelector('[data-dg-file-swap]')
    var copyEl = card.querySelector('[data-dg-file-confirm-copy]')
    var stagedInput = card.querySelector('[data-dg-file-staged]')
    var progressWrap = card.querySelector('[data-dg-file-progress]')
    var progressBar = card.querySelector('[data-dg-file-progress-bar]')
    var statusEl = card.querySelector('[data-dg-file-status]')
    var objectUrl = ''
    var stagedUrl = ''
    var xhr = null
    var stagedToken = ''

    function revokeUrl() {
      if (objectUrl) {
        URL.revokeObjectURL(objectUrl)
        objectUrl = ''
      }
    }

    function setCopy(mode) {
      if (!copyEl) {
        return
      }
      var idle = copyEl.getAttribute('data-idle') || ''
      var ready = copyEl.getAttribute('data-ready') || ''
      copyEl.textContent = mode === 'ready' ? ready : idle
    }

    function setProgress(pct) {
      if (progressBar) {
        progressBar.style.width = Math.max(0, Math.min(100, pct)) + '%'
      }
      if (progressWrap) {
        if (pct >= 0) {
          progressWrap.hidden = false
          progressWrap.removeAttribute('hidden')
        } else {
          progressWrap.hidden = true
          progressWrap.setAttribute('hidden', '')
        }
      }
    }

    function setStatus(text) {
      if (!statusEl) {
        return
      }
      if (text) {
        statusEl.textContent = text
        statusEl.hidden = false
        statusEl.removeAttribute('hidden')
      } else {
        statusEl.textContent = ''
        statusEl.hidden = true
        statusEl.setAttribute('hidden', '')
      }
    }

    function forgetRemoteToken(token) {
      if (!token) {
        return
      }
      var cfg = publicConfig()
      var base = cfg.stageUrl
        ? String(cfg.stageUrl).replace(/\/portals\/\d+\/files\/?$/, '/files/')
        : ''
      if (!base || typeof XMLHttpRequest === 'undefined') {
        return
      }
      try {
        var del = new XMLHttpRequest()
        del.open('DELETE', base + encodeURIComponent(token), true)
        if (cfg.nonce) {
          del.setRequestHeader('X-WP-Nonce', cfg.nonce)
        }
        del.send()
      } catch (err) {
        /* best-effort */
      }
    }

    function clearStaged(forgetRemote) {
      if (forgetRemote && stagedToken) {
        forgetRemoteToken(stagedToken)
      }
      stagedToken = ''
      stagedUrl = ''
      if (stagedInput) {
        stagedInput.value = ''
      }
      card.classList.remove('is-staged', 'is-uploading', 'is-working')
      setProgress(-1)
      setStatus('')
    }

    function abortXhr() {
      if (xhr) {
        try {
          xhr.abort()
        } catch (err) {
          /* ignore */
        }
        xhr = null
      }
    }

    function setOpenLabel(opened) {
      if (!openBtn) {
        return
      }
      var idle = openBtn.getAttribute('data-label-idle') || openBtn.textContent
      var openedLabel = openBtn.getAttribute('data-label-opened') || idle
      openBtn.textContent = opened ? openedLabel : idle
    }

    function markOpened() {
      card.classList.add('is-opened', 'is-confirmed')
      card.classList.remove('is-invalid')
      setOpenLabel(true)
      setCopy('ready')
    }

    function resetConfirm() {
      card.classList.remove('is-opened', 'is-confirmed', 'is-invalid')
      setOpenLabel(false)
      setCopy('idle')
    }

    function showLocalMeta(file) {
      if (nameEl) {
        nameEl.textContent = file ? file.name : ''
      }
      if (sizeEl) {
        sizeEl.textContent = file ? formatSize(file.size) : ''
      }
      if (originalEl) {
        originalEl.textContent = ''
      }
      if (sepEl) {
        sepEl.hidden = true
        sepEl.setAttribute('hidden', '')
      }
    }

    function showStagedMeta(payload, localFile) {
      var stored = payload.storedName || (localFile && localFile.name) || ''
      var original = payload.originalName || (localFile && localFile.name) || ''
      var bytes = typeof payload.bytes === 'number' ? payload.bytes : localFile ? localFile.size : 0
      if (nameEl) {
        nameEl.textContent = stored
      }
      if (sizeEl) {
        sizeEl.textContent = formatSize(bytes)
      }
      if (originalEl) {
        originalEl.textContent = original
      }
      if (sepEl) {
        sepEl.hidden = false
        sepEl.removeAttribute('hidden')
      }
    }

    function setError(message) {
      card.classList.add('is-invalid')
      var err = card.querySelector('.dg-file-error')
      if (err && message) {
        err.textContent = message
      }
    }

    function stageFile(file) {
      var url = stageUrl()
      if (!url) {
        // No stage endpoint configured (CLI/static render): local-only path.
        card.classList.add('is-ready')
        return
      }

      abortXhr()
      clearStaged(true)
      card.classList.add('is-ready', 'is-uploading')
      card.classList.remove('is-invalid', 'is-working', 'is-staged')
      setProgress(0)
      setStatus('')

      var fieldId = card.getAttribute('data-dg-field-id') || ''
      var body = new FormData()
      body.append('file', file, file.name)
      body.append('field_id', fieldId)
      var cfg = publicConfig()
      if (cfg.nonce) {
        body.append('_wpnonce', cfg.nonce)
      }

      xhr = new XMLHttpRequest()
      xhr.open('POST', url, true)
      if (cfg.nonce) {
        xhr.setRequestHeader('X-WP-Nonce', cfg.nonce)
      }

      function enterWorking() {
        card.classList.remove('is-uploading')
        card.classList.add('is-working')
        setProgress(-1)
        setStatus(STATUS_WORKING)
      }

      xhr.upload.addEventListener('progress', function (event) {
        if (!event.lengthComputable) {
          return
        }
        var pct = event.total ? (event.loaded / event.total) * 100 : 0
        setProgress(pct)
        // Progress may report 100% before the request finishes sending.
        if (event.loaded >= event.total) {
          enterWorking()
        }
      })

      // Bytes finished sending; server is still anonymizing / responding.
      // Tiny uploads often never fire progress at 100% — this is the reliable edge.
      xhr.upload.addEventListener('load', function () {
        enterWorking()
      })

      xhr.addEventListener('load', function () {
        var req = xhr
        xhr = null
        card.classList.remove('is-uploading')
        var ok = req.status >= 200 && req.status < 300
        var payload = null
        try {
          payload = JSON.parse(req.responseText || '{}')
        } catch (err) {
          payload = null
        }
        if (!ok || !payload || !payload.token) {
          card.classList.remove('is-working', 'is-staged')
          setProgress(-1)
          setStatus('')
          setError(STAGE_FAIL)
          return
        }
        stagedToken = String(payload.token)
        stagedUrl = payload.url ? String(payload.url) : ''
        if (stagedInput) {
          stagedInput.value = stagedToken
        }
        card.classList.remove('is-working')
        card.classList.add('is-ready', 'is-staged')
        setProgress(-1)
        setStatus('')
        showStagedMeta(payload, file)
      })

      xhr.addEventListener('error', function () {
        xhr = null
        card.classList.remove('is-uploading', 'is-working', 'is-staged')
        setProgress(-1)
        setStatus('')
        setError(STAGE_FAIL)
      })

      xhr.addEventListener('abort', function () {
        xhr = null
        card.classList.remove('is-uploading', 'is-working')
        setProgress(-1)
        setStatus('')
      })

      xhr.send(body)
    }

    function sync() {
      var file = input.files && input.files[0]
      abortXhr()
      // stageFile clears+forgets; empty pick only clears local state.
      if (!file) {
        clearStaged(true)
      }
      card.classList.toggle('is-empty', !file)
      card.classList.toggle('is-ready', !!file)
      showLocalMeta(file)
      revokeUrl()
      resetConfirm()
      if (file) {
        stageFile(file)
      }
    }

    input.addEventListener('change', sync)

    if (openBtn) {
      openBtn.addEventListener('click', function () {
        var file = input.files && input.files[0]
        var requiresStage = !!stageUrl()
        if (requiresStage && !stagedToken) {
          return
        }
        var url = ''
        if (stagedUrl) {
          url = stagedUrl
        } else if (requiresStage && stagedToken && !stagedUrl) {
          var cfg = publicConfig()
          var base = cfg.stageUrl
            ? String(cfg.stageUrl).replace(/\/portals\/\d+\/files\/?$/, '/files/')
            : ''
          if (base) {
            url = base + encodeURIComponent(stagedToken)
          }
        } else if (file) {
          revokeUrl()
          objectUrl = URL.createObjectURL(file)
          url = objectUrl
        }
        if (!url) {
          return
        }
        previewReturnFocus = openBtn
        openConfirmedPreview(
          card,
          url,
          function () {
            markOpened()
          },
          function () {
            setError(FILE_OPEN_FAIL)
          },
        )
      })
    }

    if (swapBtn) {
      swapBtn.addEventListener('click', function () {
        abortXhr()
        clearStaged(true)
        input.click()
      })
    }

    card.addEventListener('dragover', function (event) {
      event.preventDefault()
      card.classList.add('is-dragover')
    })
    card.addEventListener('dragleave', function () {
      card.classList.remove('is-dragover')
    })
    card.addEventListener('drop', function (event) {
      event.preventDefault()
      card.classList.remove('is-dragover')
      if (!event.dataTransfer || !event.dataTransfer.files.length) {
        return
      }
      try {
        input.files = event.dataTransfer.files
      } catch (err) {
        return
      }
      input.dispatchEvent(new Event('change', { bubbles: true }))
    })

    card.addEventListener('keydown', function (event) {
      if (event.key !== 'Enter' && event.key !== ' ') {
        return
      }
      if (event.target !== card.querySelector('.dg-file-pick') && event.target !== card) {
        return
      }
      event.preventDefault()
      input.click()
    })

    sync()
  }

  function bindSubmitGate(form) {
    form.addEventListener('submit', function (event) {
      var cards = form.querySelectorAll('.dg-file.is-ready')
      var blocked = false
      var requireStage = !!stageUrl()
      for (var i = 0; i < cards.length; i += 1) {
        var card = cards[i]
        var staged = card.querySelector('[data-dg-file-staged]')
        var missingOpen = !card.classList.contains('is-opened')
        var missingToken = requireStage && staged && !staged.value
        if (missingOpen || missingToken) {
          card.classList.add('is-invalid')
          blocked = true
        }
      }
      if (blocked) {
        event.preventDefault()
        var first = form.querySelector('.dg-file.is-invalid')
        if (first) {
          first.scrollIntoView({ block: 'center' })
        }
        return
      }
      var submit = form.querySelector('[type="submit"]')
      if (submit && !submit.disabled) {
        submit.disabled = true
        submit.setAttribute('aria-busy', 'true')
        submit.setAttribute('data-dg-working', '1')
        if (!submit.getAttribute('data-dg-label')) {
          submit.setAttribute('data-dg-label', submit.textContent || 'Submit')
        }
        submit.textContent = ''
        var spinner = document.createElement('span')
        spinner.className = 'dg-submit-spinner'
        spinner.setAttribute('aria-hidden', 'true')
        submit.appendChild(spinner)
        submit.appendChild(document.createTextNode(STATUS_SUBMITTING))
      }
    })
  }

  /**
   * Direct child panels of a fieldset (not nested branch panels).
   * @param {HTMLFieldSetElement} fieldset
   * @returns {Element[]}
   */
  function directBranchPanels(fieldset) {
    var out = []
    var kids = fieldset.children
    for (var i = 0; i < kids.length; i += 1) {
      var el = kids[i]
      if (el.classList && el.classList.contains('dg-branch-children')) {
        out.push(el)
      }
    }
    return out
  }

  /**
   * Enable or disable form controls inside a panel.
   * Skips controls that belong to a nested hidden branch panel.
   * @param {Element} panel
   * @param {boolean} enabled
   */
  function setPanelControlsEnabled(panel, enabled) {
    var controls = panel.querySelectorAll('input, select, textarea, button')
    for (var i = 0; i < controls.length; i += 1) {
      var control = controls[i]
      var nestedHidden = control.closest('.dg-branch-children[hidden]')
      if (nestedHidden && nestedHidden !== panel) {
        continue
      }
      if (enabled) {
        if (control.getAttribute('data-dg-was-disabled') === '1') {
          control.removeAttribute('data-dg-was-disabled')
          control.disabled = false
        }
      } else if (!control.disabled) {
        control.setAttribute('data-dg-was-disabled', '1')
        control.disabled = true
      }
    }
  }

  /**
   * Bind one branch fieldset: radios toggle sibling `.dg-branch-children`.
   * @param {Element} root  [data-dg-field-type="branch"]
   */
  function bindBranchField(root) {
    var fieldset = root.querySelector('fieldset')
    if (!fieldset) {
      return
    }
    var panels = directBranchPanels(fieldset)
    if (!panels.length) {
      return
    }
    var optionIds = []
    for (var p = 0; p < panels.length; p += 1) {
      optionIds.push(panels[p].getAttribute('data-dg-branch-option') || '')
    }
    var radios = fieldset.querySelectorAll('input[type="radio"]')
    // Only radios whose name matches this branch (first radio’s name).
    var branchName = radios.length ? radios[0].name : ''
    var ownRadios = []
    for (var r = 0; r < radios.length; r += 1) {
      // Skip radios that live inside a nested branch panel.
      var inPanel = false
      for (var q = 0; q < panels.length; q += 1) {
        if (panels[q].contains(radios[r])) {
          inPanel = true
          break
        }
      }
      if (!inPanel && radios[r].name === branchName) {
        ownRadios.push(radios[r])
      }
    }

    function selectedId() {
      for (var i = 0; i < ownRadios.length; i += 1) {
        if (ownRadios[i].checked) {
          return ownRadios[i].value
        }
      }
      return ''
    }

    function apply() {
      var plan = visibleBranchOptions(selectedId(), optionIds)
      for (var i = 0; i < panels.length; i += 1) {
        var panel = panels[i]
        var optId = panel.getAttribute('data-dg-branch-option') || ''
        var visible = false
        for (var j = 0; j < plan.length; j += 1) {
          if (plan[j].id === optId) {
            visible = plan[j].visible
            break
          }
        }
        if (visible) {
          panel.removeAttribute('hidden')
          setPanelControlsEnabled(panel, true)
        } else {
          panel.setAttribute('hidden', '')
          setPanelControlsEnabled(panel, false)
        }
      }
    }

    for (var i = 0; i < ownRadios.length; i += 1) {
      ownRadios[i].addEventListener('change', apply)
    }
    apply()
  }

  function bindBranches(root) {
    var branches = (root || document).querySelectorAll('[data-dg-field-type="branch"]')
    for (var i = 0; i < branches.length; i += 1) {
      bindBranchField(branches[i])
    }
  }

  function init() {
    forceNewTabLinks(document)
    var cards = document.querySelectorAll('.dg-file')
    for (var i = 0; i < cards.length; i += 1) {
      bindFileCard(cards[i])
    }
    bindBranches(document)
    var form = document.querySelector('.dg-packet')
      ? document.querySelector('.dg-packet').closest('form')
      : document.querySelector('form')
    if (form) {
      bindSubmitGate(form)
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init)
  } else {
    init()
  }
})()

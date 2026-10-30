<script>
import { untrack } from 'svelte';
	import { Combobox, Label } from 'bits-ui';
	import Field from '../../ui/Field.svelte';
	import {
		createInvite,
		getRecords,
		listInvites,
		recallPacket,
		replacePacketFile,
		resendInvite,
		revokeInvite,
		searchInviteCandidates,
	} from '../recordsApi.js';

	/**
	 * @typedef {Object} Props
	 * @property {object} definition
	 * @property {{ restRoot: string, nonce: string, portalId: number, wpRestRoot: string }} api
	 * @property {() => void} [onBack]
	 */

	/** @type {Props} */
	let { definition, api, onBack = () => {} } = $props();

	const PAGE_SIZE = 5;
	const EXPIRY_OPTIONS = [
		{ value: '7', label: '7 days' },
		{ value: '14', label: '14 days' },
		{ value: '30', label: '30 days' },
		{ value: '60', label: '60 days' },
		{ value: '90', label: '90 days' },
	];

	let records = $state(/** @type {object[]} */ ([]));
	let recordsTotal = $state(0);
	let recordsLimit = $state(PAGE_SIZE);
	let recordsState = $state(/** @type {'loading'|'ready'|'error'} */ ('loading'));
	let recordsError = $state('');
	let packetBusyId = $state('');
	let packetError = $state('');
	let packetNote = $state('');
	let replacementFields = $state(/** @type {Record<string, string>} */ ({}));

	let invites = $state(/** @type {object[]} */ ([]));
	let invitesState = $state(/** @type {'loading'|'ready'|'error'} */ ('loading'));
	let invitesError = $state('');

	let inviteEmail = $state('');
	let inviteName = $state('');
	let inviteUserId = $state(0);
	let selectedUser = $state(/** @type {{ name: string, login: string }|null} */ (null));
	let expiresInDays = $state('14');
	let sendState = $state(/** @type {'idle'|'sending'|'sent'|'failed'} */ ('idle'));
	let sendNote = $state('');
	let sendError = $state('');

	let searchQuery = $state('');
	let candidates = $state(/** @type {object[]} */ ([]));
	let searchState = $state(/** @type {'idle'|'loading'|'ready'|'error'} */ ('idle'));
	let searchError = $state('');
	let searchOpen = $state(false);

	let rowBusyId = $state(/** @type {number|string|null} */ (null));
	let rowActionError = $state('');
	let copyInviteId = $state(/** @type {number|string|null} */ (null));

	// Request generations keep responses from an old portal or search out of this view.
	let searchTimer = 0;
	let searchSeq = 0;
	let recordsRequest = 0;
	let invitesRequest = 0;

	$effect(() => {
		const config = cfg();
		// Only credentials are dependencies; paging and request state must not
		// tear down the initial load or the pending candidate search.
		untrack(() => {
			loadRecords(PAGE_SIZE, config);
			loadInvites(config);
		});
		return () => {
			recordsRequest++;
			invitesRequest++;
			searchSeq++;
			window.clearTimeout(searchTimer);
		};
	});

	function cfg() {
		return {
			restRoot: api?.restRoot,
			nonce: api?.nonce,
			portalId: api?.portalId,
		};
	}

	async function loadRecords(limit = recordsLimit, config = cfg()) {
		const request = ++recordsRequest;
		recordsState = 'loading';
		try {
			const data = await getRecords({ ...config, limit });
			if (request !== recordsRequest) return;
			records = data.records;
			recordsTotal = data.total;
			recordsLimit = limit;
			recordsState = 'ready';
			recordsError = '';
		} catch (e) {
			if (request !== recordsRequest) return;
			records = [];
			recordsState = 'error';
			recordsError = e instanceof Error ? e.message : 'Records load failed';
		}
	}

	async function loadInvites(config = cfg()) {
		const request = ++invitesRequest;
		invitesState = 'loading';
		try {
			const rows = await listInvites(config);
			if (request !== invitesRequest) return;
			invites = rows;
			invitesState = 'ready';
			invitesError = '';
		} catch (e) {
			if (request !== invitesRequest) return;
			invites = [];
			invitesState = 'error';
			invitesError = e instanceof Error ? e.message : 'Invitations load failed';
		}
	}

	function showAll() {
		loadRecords(recordsTotal > 0 ? recordsTotal : recordsLimit);
	}

	/**
	 * @param {object} row
	 * @param {Event & { currentTarget: HTMLInputElement }} event
	 */
	async function replaceFile(row, event) {
		const file = event.currentTarget.files?.[0];
		event.currentTarget.value = '';
		if (!file) return;
		packetBusyId = row.applicationId;
		packetError = '';
		packetNote = '';
		try {
			const result = await replacePacketFile({
				...cfg(),
				applicationId: row.applicationId,
				fieldId: replacementFields[row.applicationId].trim(),
				file,
			});
			packetNote = `Replaced ${result.replacedField}; wrote to ${result.replacementDestination}.`;
			await loadRecords();
		} catch (e) {
			packetError = e instanceof Error ? e.message : 'File replacement failed';
		} finally {
			packetBusyId = '';
		}
	}

	/** @param {object} row */
	async function recallRow(row) {
		if (!window.confirm(`Recall application ${row.applicationId}? Staff will no longer treat this packet as current.`)) return;
		packetBusyId = row.applicationId;
		packetError = '';
		packetNote = '';
		try {
			await recallPacket({ ...cfg(), applicationId: row.applicationId });
			packetNote = `Recalled application ${row.applicationId}.`;
			await loadRecords();
		} catch (e) {
			packetError = e instanceof Error ? e.message : 'Packet recall failed';
		} finally {
			packetBusyId = '';
		}
	}

	/**
	 * @param {Event & { currentTarget: HTMLInputElement }} e
	 */
	function onSearchInput(e) {
		const value = e.currentTarget.value;
		searchQuery = value;
		const seq = ++searchSeq;
		window.clearTimeout(searchTimer);
		candidates = [];
		searchError = '';
		searchOpen = value.trim() !== '';
		if (value.trim() === '') {
			searchState = 'idle';
			return;
		}
		searchState = 'loading';
		searchTimer = window.setTimeout(async () => {
			try {
				const rows = await searchInviteCandidates({
					...cfg(),
					search: value.trim(),
					limit: 20,
				});
				if (seq !== searchSeq) return;
				candidates = rows;
				searchState = 'ready';
				searchError = '';
			} catch (err) {
				if (seq !== searchSeq) return;
				candidates = [];
				searchState = 'error';
				searchError = err instanceof Error ? err.message : 'User search failed';
			}
		}, 250);
	}

	function selectCandidate(value) {
		const candidate = candidates.find((row) => String(row.userId) === value);
		if (candidate) pickCandidate(candidate);
	}

	/**
	 * @param {object} c
	 */
	function pickCandidate(c) {
		searchSeq++;
		window.clearTimeout(searchTimer);
		searchOpen = false;
		inviteEmail = c.email || '';
		inviteUserId = Number(c.userId) || 0;
		selectedUser = {
			name: c.name || c.login || `user ${c.userId}`,
			login: c.login || '',
		};
		candidates = [];
		searchQuery = '';
		searchState = 'idle';
		sendState = 'idle';
	}

	/**
	 * @param {Event & { currentTarget: HTMLInputElement }} e
	 */
	function onEmailInput(e) {
		inviteEmail = e.currentTarget.value;
		// A typed address is a different person than the picked WordPress user.
		inviteUserId = 0;
		selectedUser = null;
	}

	async function sendInvite() {
		const email = inviteEmail.trim();
		if (!email) {
			sendState = 'failed';
			sendNote = '';
			sendError = 'Enter an email address.';
			return;
		}
		sendState = 'sending';
		sendNote = '';
		sendError = '';
		try {
			const body = await createInvite({
				...cfg(),
				email,
				name: inviteName.trim() || undefined,
				userId: inviteUserId || undefined,
				expiresInDays: Number(expiresInDays) || 14,
			});
			sendState = 'sent';
			sendNote = body?.resent ? 'Invite resent.' : 'Invite sent.';
			inviteEmail = '';
			inviteName = '';
			inviteUserId = 0;
			selectedUser = null;
			await loadInvites();
		} catch (e) {
			sendState = 'failed';
			sendError = e instanceof Error ? e.message : 'Invite failed';
		}
	}

	/**
	 * @param {object} row
	 */
	async function resendRow(row) {
		rowBusyId = row.id;
		rowActionError = '';
		try {
			await resendInvite({ ...cfg(), inviteId: row.id });
			await loadInvites();
		} catch (e) {
			rowActionError = e instanceof Error ? e.message : 'Resend failed';
		} finally {
			rowBusyId = null;
		}
	}

	/**
	 * @param {object} row
	 */
	async function revokeRow(row) {
		rowBusyId = row.id;
		rowActionError = '';
		try {
			await revokeInvite({ ...cfg(), inviteId: row.id });
			await loadInvites();
		} catch (e) {
			rowActionError = e instanceof Error ? e.message : 'Revoke failed';
		} finally {
			rowBusyId = null;
		}
	}

	/**
	 * @param {object} row
	 */
	async function copyInviteLink(row) {
		if (!row.url) return;
		try {
			await navigator.clipboard.writeText(row.url);
			copyInviteId = row.id;
			window.setTimeout(() => {
				if (copyInviteId === row.id) copyInviteId = null;
			}, 2000);
		} catch {
			window.prompt('Copy invite link:', row.url);
		}
	}

	/**
	 * @param {unknown} value
	 */
	function formatDateTime(value) {
		if (!value || typeof value !== 'string') return '—';
		const d = new Date(value);
		return Number.isNaN(d.getTime()) ? value : d.toLocaleString();
	}

	/**
	 * @param {unknown} value
	 */
	function formatDate(value) {
		if (!value || typeof value !== 'string') return '—';
		const d = new Date(value);
		return Number.isNaN(d.getTime()) ? value : d.toLocaleDateString();
	}

	/**
	 * @param {unknown} value
	 */
	function truncateId(value) {
		const s = value == null ? '' : String(value);
		if (!s) return '—';
		return s.length > 18 ? `${s.slice(0, 16)}…` : s;
	}

	/**
	 * @param {object} row
	 */
	function userCell(row) {
		if (!row.userId) return '—';
		const display = row.userDisplay || row.userLogin || `user ${row.userId}`;
		const login = row.userLogin && row.userLogin !== row.userDisplay ? ` (${row.userLogin})` : '';
		return `${display}${login}`;
	}

	/**
	 * @param {unknown} status
	 */
	function inviteStatusLabel(status) {
		const s = status == null ? '' : String(status);
		if (!s) return '—';
		return s.charAt(0).toUpperCase() + s.slice(1);
	}
</script>

<p class="dg-wizard-eyebrow">{definition?.title || 'Portal'}</p>
<h2 id="dg-wizard-step-title" class="dg-wizard-title">
  Submissions and invitations
</h2>
<p class="dg-wizard-lead">
  Completed submissions for this portal, and invitations for applicants who
  should submit after it closes.
</p>

<div class="dg-publish-card cardish">
  <h3 class="dg-section-label" id="dg-records-heading">Recent submissions</h3>
  {#if recordsState === 'loading'}
    <p class="dg-field-help">Loading submissions…</p>
  {:else if recordsState === 'error'}
    <p class="dg-wizard-alert" role="alert">{recordsError}</p>
  {:else if records.length === 0}
    <p><strong>No submissions yet.</strong></p>
    <p class="dg-field-help">
      Each completed submission lands in the portal's Google Sheet and Drive
      folder.
    </p>
  {:else}
    <table class="dg-records-table" aria-labelledby="dg-records-heading">
      <thead>
        <tr>
          <th scope="col">Applicant</th>
          <th scope="col">WordPress user</th>
          <th scope="col">Submitted</th>
          <th scope="col">Application</th>
          <th scope="col">Status</th>
          <th scope="col">Sheet</th>
          <th scope="col">Drive</th>
          <th scope="col">Anonymize</th>
          <th scope="col">Mail</th>
          <th scope="col">Error code</th>
          <th scope="col">Packet actions</th>
        </tr>
      </thead>
      <tbody>
        {#each records as row}
          <tr>
            <td>{row.name || '—'}</td>
            <td>{userCell(row)}</td>
            <td>{formatDateTime(row.time)}</td>
            <td
              class="dg-mono"
              title={row.applicationId ? String(row.applicationId) : undefined}
            >
              {truncateId(row.applicationId)}
            </td>
            <td>{row.status || '—'}</td>
            <td>{row.dests?.sheet ?? '—'}</td>
            <td>{row.dests?.drive ?? '—'}</td>
            <td>{row.dests?.anonymize ?? '—'}</td>
            <td>{row.dests?.mail ?? '—'}</td>
            <td>{row.errorCode || '—'}</td>
            <td>
              <div class="dg-link-actions">
                {#if row.canReplace}
                  <input
                    class="dg-input"
                    type="text"
                    placeholder="File field ID"
                    aria-label={`Replacement field ID for application ${row.applicationId}`}
                    bind:value={replacementFields[row.applicationId]}
                    disabled={packetBusyId !== ''}
                  />
                  <input
                    id={`dg-replace-${row.applicationId}`}
                    type="file"
                    hidden
                    aria-label={`Choose replacement file for application ${row.applicationId}`}
                    onchange={(event) => replaceFile(row, event)}
                    disabled={packetBusyId !== ''}
                  />
                  <button
                    type="button"
                    class="dg-btn dg-btn-ghost dg-btn-sm"
                    aria-label={`Replace file for application ${row.applicationId}`}
                    disabled={packetBusyId !== '' || !replacementFields[row.applicationId]?.trim()}
                    onclick={() => document.getElementById(`dg-replace-${row.applicationId}`)?.click()}
                  >
                    Replace file
                  </button>
                {/if}
                {#if row.canRecall}
                  <button
                    type="button"
                    class="dg-btn dg-btn-ghost dg-btn-sm"
                    aria-label={`Recall application ${row.applicationId}`}
                    disabled={packetBusyId !== ''}
                    onclick={() => recallRow(row)}
                  >
                    Recall
                  </button>
                {/if}
              </div>
            </td>
          </tr>
        {/each}
      </tbody>
    </table>
    <p class="dg-field-help" aria-live="polite">
      {#if records.length < recordsTotal}
        Showing the {records.length} most recent of {recordsTotal}.
        <button
          type="button"
          class="dg-btn dg-btn-ghost dg-btn-sm"
          onclick={showAll}
        >
          Show all
        </button>
      {:else}
        Showing all {recordsTotal}.
      {/if}
    </p>
  {/if}
  {#if packetBusyId}
    <p class="dg-field-help" role="status">
      Updating application {packetBusyId}…
    </p>
  {/if}
  {#if packetNote}
    <p class="dg-field-help" role="status">{packetNote}</p>
  {/if}
  {#if packetError}
    <p class="dg-wizard-alert" role="alert">{packetError}</p>
  {/if}
</div>

<div class="dg-publish-card cardish">
  <h3 class="dg-section-label" id="dg-invites-heading">Invitations</h3>
  <p class="dg-field-help">
    Invite an applicant to submit after the portal closes.
  </p>

  <div class="dg-publish-block dg-invite-search">
    <Label.Root for="dg-invite-search" class="dg-field-label"
    >Find a WordPress user</Label.Root>
    <Combobox.Root
      type="single"
      value={inviteUserId ? String(inviteUserId) : ''}
      bind:open={searchOpen}
      onValueChange={selectCandidate}
    >
      <Combobox.Input
        id="dg-invite-search"
        class="dg-input"
        placeholder="Search by name, login, or email"
        value={searchQuery}
        oninput={onSearchInput}
      />
      <Combobox.Content
        class="dg-invite-candidates"
        aria-label="WordPress users"
      >
        {#each candidates as c (c.userId)}
          <Combobox.Item
            class="dg-invite-candidate"
            value={String(c.userId)}
            label={`${c.name || c.login} (${c.login}) — ${c.email}`}
          >
            {c.name || c.login} ({c.login}) — {c.email}{
              c.hasSubmission ? ' · has a submission' : ''
            }
          </Combobox.Item>
        {/each}
        {#if searchState === 'ready' && candidates.length === 0}
          <p class="dg-field-help">No matching WordPress users.</p>
        {/if}
      </Combobox.Content>
    </Combobox.Root>
    {#if searchState === 'loading'}
      <p class="dg-field-help" role="status">Searching…</p>
    {/if}
    {#if searchState === 'error'}
      <p class="dg-wizard-alert" role="alert">{searchError}</p>
    {/if}
    {#if selectedUser}
      <p class="dg-field-help">
        WordPress user selected: {selectedUser.name}{
          selectedUser.login
          ? ` (${selectedUser.login})`
          : ''
        }. Editing the email sends the invite to someone else.
      </p>
    {/if}
  </div>

  <div class="dg-publish-block">
    <div class="dg-publish-grid">
      <div>
        <Field
          id="dg-invite-email"
          label="Email"
          type="email"
          placeholder="applicant@example.org"
          value={inviteEmail}
          oninput={onEmailInput}
        />
      </div>
      <div>
        <Field
          id="dg-invite-name"
          label="Name (optional)"
          value={inviteName}
          oninput={(e) => (inviteName = e.currentTarget.value)}
        />
      </div>
    </div>
    <p class="dg-field-help">
      For people without a WordPress account, or when you do not want to use
      theirs. Sending with only the email is fine.
    </p>
  </div>

  <div class="dg-publish-block dg-invite-expiry">
    <label for="dg-invite-expires" class="dg-field-label"
    >Invite expires in</label>
    <select id="dg-invite-expires" bind:value={expiresInDays}>
      {#each EXPIRY_OPTIONS as option}
        <option value={option.value}>{option.label}</option>
      {/each}
    </select>
  </div>

  <div class="dg-publish-block">
    <button
      type="button"
      class="dg-btn dg-btn-primary"
      disabled={sendState === 'sending'}
      onclick={sendInvite}
    >
      {sendState === 'sending' ? 'Sending…' : 'Send invite'}
    </button>
  </div>
  {#if sendState === 'sent'}
    <p class="dg-field-help" role="status">{sendNote}</p>
  {/if}
  {#if sendState === 'failed'}
    <p class="dg-wizard-alert" role="alert">{sendError}</p>
  {/if}

  <div class="dg-publish-block">
    {#if invitesState === 'loading'}
      <p class="dg-field-help">Loading invitations…</p>
    {:else if invitesState === 'error'}
      <p class="dg-wizard-alert" role="alert">{invitesError}</p>
    {:else if invites.length === 0}
      <p class="dg-field-help">No invitations yet.</p>
    {:else}
      <table class="dg-records-table" aria-labelledby="dg-invites-heading">
        <thead>
          <tr>
            <th scope="col">Email</th>
            <th scope="col">Name</th>
            <th scope="col">Status</th>
            <th scope="col">Expires</th>
            <th scope="col">Application</th>
            <th scope="col">Actions</th>
          </tr>
        </thead>
        <tbody>
          {#each invites as row (row.id)}
            <tr>
              <td>{row.email || '—'}</td>
              <td>{row.name || '—'}</td>
              <td>{inviteStatusLabel(row.status)}</td>
              <td>{formatDate(row.expiresAt)}</td>
              <td
                class="dg-mono"
                title={row.applicationId ? String(row.applicationId) : undefined}
              >
                {truncateId(row.applicationId)}
              </td>
              <td>
                <div class="dg-link-actions">
                  <button
                    type="button"
                    class="dg-invite-action"
                    aria-label={`Copy invite link for ${row.email || 'invitation'}`}
                    onclick={() => copyInviteLink(row)}
                  >
                    {copyInviteId === row.id ? 'Copied ✓' : 'Copy link'}
                  </button>
                  <button
                    type="button"
                    class="dg-invite-action"
                    aria-label={`Resend invite to ${row.email || 'invitation'}`}
                    disabled={rowBusyId === row.id}
                    onclick={() => resendRow(row)}
                  >
                    {rowBusyId === row.id ? 'Working…' : 'Resend'}
                  </button>
                  <button
                    type="button"
                    class="dg-invite-action"
                    aria-label={`Revoke invite for ${row.email || 'invitation'}`}
                    disabled={rowBusyId === row.id}
                    onclick={() => revokeRow(row)}
                  >
                    Revoke
                  </button>
                </div>
              </td>
            </tr>
          {/each}
        </tbody>
      </table>
    {/if}
    {#if rowActionError}
      <p class="dg-wizard-alert" role="alert">{rowActionError}</p>
    {/if}
  </div>
</div>

<div class="dg-wizard-actions dg-wizard-actions--spread">
  <button type="button" class="dg-btn dg-btn-ghost" onclick={onBack}>
    ← Back to status
  </button>
</div>

<style>
.dg-records-table {
  width: 100%;
  border-collapse: collapse;
  font-size: var(--t-small);
  margin-top: var(--s-2);
}
.dg-records-table th,
.dg-records-table td {
  text-align: left;
  vertical-align: top;
  padding: 8px 10px;
  border-top: 1px solid var(--rule);
}
.dg-records-table th {
  font-family: var(--font-mono);
  font-size: 10.5px;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--ink-400);
  background: var(--paper-100);
  border-top: none;
}
.dg-records-table td.dg-mono {
  font-size: 11.5px;
}
.dg-invite-expiry select {
  appearance: auto;
  width: auto;
  max-width: 100%;
  min-height: 36px;
  padding: 6px 10px;
  border: 1px solid var(--rule-strong);
  border-radius: 4px;
  background-color: var(--paper-50);
  background-image: none;
  color: var(--ink);
  font: inherit;
}
.dg-invite-search :global(.dg-invite-candidates) {
  z-index: 50;
  width: var(--bits-combobox-anchor-width);
  max-height: 240px;
  overflow-y: auto;
  padding: var(--s-1);
  border: 1px solid var(--rule-strong);
  border-radius: 4px;
  background: var(--paper-50);
  color: var(--ink);
}
.dg-invite-search :global(.dg-invite-candidate) {
  padding: 8px 12px;
  border-radius: 3px;
  cursor: pointer;
  font-size: var(--t-body);
  line-height: var(--lh-snug);
}
.dg-invite-search :global(.dg-invite-candidate:hover),
.dg-invite-search :global(.dg-invite-candidate[data-highlighted]),
.dg-invite-search :global(.dg-invite-candidate[data-selected]) {
  background: var(--paper-200);
}
.dg-invite-search :global(.dg-invite-candidate[data-highlighted]) {
  outline: 2px solid var(--ember);
  outline-offset: -2px;
}
.dg-invite-action {
  padding: 2px 0;
  border: 0;
  background: transparent;
  color: var(--ink-600);
  font: inherit;
  font-size: var(--t-small);
  text-decoration: underline;
  text-underline-offset: 3px;
  cursor: pointer;
}
.dg-invite-action:hover {
  color: var(--ember);
}
.dg-invite-action:disabled {
  opacity: 0.5;
  cursor: default;
}
.dg-invite-action:focus-visible,
.dg-invite-expiry select:focus-visible {
  outline: 2px solid var(--ember);
  outline-offset: 2px;
}
</style>

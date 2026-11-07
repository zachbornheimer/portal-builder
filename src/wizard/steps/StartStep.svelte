<script>
	import {
		blankDefinition,
		callForScoresTemplate,
		cloneDefinition,
		composerPrizeTemplate,
		genericStarterTemplate,
	} from '../definitionModel.js';
	import { getDefinition, listPortalTemplates } from '../definitionApi.js';

	/**
	 * @typedef {Object} Props
	 * @property {object} definition
	 * @property {(d: object) => void} onApply
	 * @property {() => void} onContinue
	 * @property {{ restRoot: string, nonce: string, portalId: number, wpRestRoot: string }} api
	 */

	/** @type {Props} */
	let { definition, onApply, onContinue, api } = $props();

	let clones = $state(/** @type {{ id: number, title: string }[]} */ ([]));
	let loadError = $state('');
	let busyId = $state(/** @type {string|number|null} */ (null));

	$effect(() => {
		let cancelled = false;
		(async () => {
			try {
				const list = await listPortalTemplates({
					wpRestRoot: api.wpRestRoot,
					nonce: api.nonce,
				});
				if (!cancelled) {
					clones = list.filter((p) => p.id !== api.portalId).slice(0, 6);
				}
			} catch {
				if (!cancelled) clones = [];
			}
		})();
		return () => {
			cancelled = true;
		};
	});

	/**
	 * @param {object} next
	 */
	function applyAndContinue(next) {
		onApply(next);
		onContinue();
	}

	function useStarter() {
		busyId = 'starter';
		const title = definition?.title || 'New portal';
		applyAndContinue(genericStarterTemplate(title));
		busyId = null;
	}

	function useComposer() {
		busyId = 'composer';
		const title = definition?.title || 'Composer Prize';
		applyAndContinue(composerPrizeTemplate(title));
		busyId = null;
	}

	function useCallForScores() {
		busyId = 'cfs';
		const title = definition?.title || '2027 Call for Scores and Papers';
		applyAndContinue(callForScoresTemplate(title));
		busyId = null;
	}

	function useBlank() {
		busyId = 'blank';
		const title = definition?.title || 'New portal';
		applyAndContinue(blankDefinition(title));
		busyId = null;
	}

	/**
	 * @param {number} portalId
	 * @param {string} title
	 */
	async function useClone(portalId, title) {
		busyId = portalId;
		loadError = '';
		try {
			const src = await getDefinition({
				restRoot: api.restRoot,
				nonce: api.nonce,
				portalId,
			});
			if (!src || !Array.isArray(src.fields)) {
				loadError = `“${title}” has no structured definition to clone. Use a template instead.`;
				return;
			}
			const next = cloneDefinition(src);
			next.title = definition?.title || title;
			applyAndContinue(next);
		} catch (e) {
			loadError = e instanceof Error ? e.message : 'Could not clone portal.';
		} finally {
			busyId = null;
		}
	}
</script>

<p class="dg-wizard-eyebrow">New portal</p>
<h2 id="dg-wizard-step-title" class="dg-wizard-title">How do you want to start?</h2>
<p class="dg-wizard-lead">
	Start from a simple application or a named example. Then adjust deadline and fee.
</p>

{#if loadError}
	<p class="dg-wizard-alert" role="alert">{loadError}</p>
{/if}

<div class="dg-template-grid" data-dg-start-templates>
	<article class="dg-template-card dg-template-card--featured">
		<span class="dg-field-type-pill">Recommended</span>
		<h3 class="dg-template-card-title">Basic application</h3>
		<p class="dg-template-card-help">
			Applicant information, a title, and one file. Add more fields in Build.
		</p>
		<button
			type="button"
			class="dg-btn dg-btn-primary dg-btn-block"
			disabled={busyId !== null}
			onclick={useStarter}
		>
			{busyId === 'starter' ? 'Applying…' : 'Use this template'}
		</button>
	</article>

	<article class="dg-template-card">
		<span class="dg-field-type-pill">Example</span>
		<h3 class="dg-template-card-title">Call for Scores</h3>
		<p class="dg-template-card-help">
			2027 Call for Scores: poster, papers, or scores — nested masterclass / First Takes / student paths.
		</p>
		<button
			type="button"
			class="dg-btn dg-btn-ghost dg-btn-block"
			disabled={busyId !== null}
			onclick={useCallForScores}
		>
			{busyId === 'cfs' ? 'Applying…' : 'Use this template'}
		</button>
	</article>

	<article class="dg-template-card">
		<span class="dg-field-type-pill">Example</span>
		<h3 class="dg-template-card-title">Composer Prize</h3>
		<p class="dg-template-card-help">
			Title, score (PDF), recording (MP3), and applicant pack.
		</p>
		<button
			type="button"
			class="dg-btn dg-btn-ghost dg-btn-block"
			disabled={busyId !== null}
			onclick={useComposer}
		>
			{busyId === 'composer' ? 'Applying…' : 'Use this template'}
		</button>
	</article>

	<article class="dg-template-card">
		<span class="dg-field-type-pill dg-field-type-pill--blank">Blank</span>
		<h3 class="dg-template-card-title">Start from scratch</h3>
		<p class="dg-template-card-help">Empty form — add only the fields you need.</p>
		<button
			type="button"
			class="dg-btn dg-btn-ghost dg-btn-block"
			disabled={busyId !== null}
			onclick={useBlank}
		>
			{busyId === 'blank' ? 'Applying…' : 'Start blank'}
		</button>
	</article>
</div>

{#if clones.length > 0}
	<h3 class="dg-section-label">Clone a published portal</h3>
	<ul class="dg-clone-list">
		{#each clones as p (p.id)}
			<li>
				<button
					type="button"
					class="dg-btn dg-btn-ghost dg-btn-block"
					disabled={busyId !== null}
					onclick={() => useClone(p.id, p.title)}
				>
					{busyId === p.id ? 'Cloning…' : p.title}
				</button>
			</li>
		{/each}
	</ul>
{/if}

<p class="dg-wizard-footnote">
	You can change fields later in Build. Saving writes the structured definition (not WordPress
	blocks).
</p>

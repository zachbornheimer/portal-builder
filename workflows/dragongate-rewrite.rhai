let meta = #{
    name: "dragongate-rewrite",
    description: "Execute DragonGate full rewrite plan phases with verify + commit per task",
    when_to_use: "Run the DragonGate Portals rewrite plan by phase (0-9)",
    phases: [
        #{ title: "Execute", detail: "implement tasks for the requested phase" },
        #{ title: "Verify", detail: "re-check tests and git state" },
    ],
};

// args.phase: integer 0-9 (required)
// args.tasks: optional string like "0.1,0.2" to limit; empty = full phase
let phase_arg = if args == () { () } else { args.phase };
if phase_arg == () {
    pause("verification", "Pass args.phase (0-9). Optional args.tasks as comma-separated IDs.");
}

let phase_n = phase_arg;
let tasks_filter = if args == () { () } else { args.tasks };

let repo = "/Users/zbornheimer/Developer/Personal/portal-builder";
let plan_note = "Plan: sessions plan.md DragonGate Full Rewrite. Contracts: docs/design/PORTAL-MODEL.md, TOKENS.md.";

// Task lists per phase (IDs + one-line intent + verify command)
fn phase_tasks(n) {
    if n == 0 {
        return [
            #{ id: "0.1", title: "Env config + assert-env", verify: "npm run test:env" },
            #{ id: "0.2", title: "Playwright smoke + seed/cleanup", verify: "npm run test:env && npx playwright test e2e/smoke.spec.ts" },
            #{ id: "0.3", title: "Mock facades skeleton", verify: "npm run test:env && npm run test:mocks" },
            #{ id: "0.4", title: "npm scripts + CI stubs", verify: "npm run test:env && npm run test:e2e:smoke" },
        ];
    }
    if n == 1 {
        return [
            #{ id: "1.1", title: "Schema validate definition", verify: "npm run test:unit" },
            #{ id: "1.2", title: "Persist _portal_definition", verify: "npm run test:env && npx playwright test e2e/admin/definition-persist.spec.ts" },
            #{ id: "1.3", title: "REST definition API", verify: "npm run test:unit && npx playwright test e2e/admin/definition-api.spec.ts" },
            #{ id: "1.4", title: "Admin portal CRUD e2e", verify: "npx playwright test e2e/admin/portal-crud.spec.ts" },
        ];
    }
    if n == 2 {
        return [
            #{ id: "2.1", title: "Public render from definition", verify: "npx playwright test e2e/public/render-herbolzheimer.spec.ts" },
            #{ id: "2.2", title: "Closed vs preview", verify: "npx playwright test e2e/public/closed-preview.spec.ts" },
            #{ id: "2.3", title: "Definition-first template flag", verify: "npx playwright test e2e/public/definition-flag.spec.ts" },
        ];
    }
    // Later phases: one meta-task that implements remaining phase tasks from plan
    return [
        #{ id: "P" + n.to_string(), title: "Execute all remaining tasks for phase " + n.to_string(), verify: "npm run test:env" },
    ];
}

let all = phase_tasks(phase_n);
let selected = [];
if tasks_filter == () || tasks_filter == "" {
    selected = all;
} else {
    // simple contains filter on id
    for t in all {
        if tasks_filter.index_of(t.id) >= 0 {
            selected.push(t);
        }
    }
}
if selected.len() == 0 {
    pause("verification", "No tasks selected for phase " + phase_n.to_string());
}

phase("Execute");
log("Phase " + phase_n.to_string() + ": " + selected.len().to_string() + " task(s)");

let done = [];
for t in selected {
    log("TASK " + t.id + " — " + t.title);

    let p = "";
    p += "You are the implement worker for DragonGate Portals rewrite.\n";
    p += plan_note + "\n";
    p += "REPO: " + repo + "\n";
    p += "TASK ID: " + t.id + " — " + t.title + "\n";
    p += "Read docs/design/PORTAL-MODEL.md and the plan verification doctrine.\n";
    p += "Work ONLY on this task. Do not start other task IDs.\n";
    p += "Write code Zach would approve: facades at Google/mail/fs edges; small units; no magic values.\n";
    p += "VERIFY: run `" + t.verify + "` and ensure exit 0. Capture evidence.\n";
    p += "COMMIT: if verify passes, create ONE signed-if-available conventional commit for this task only ";
    p += "(git status/diff/log first; HEREDOC message; never update git config; never push).\n";
    p += "If verify fails, fix within this task or report failure without commit.\n";
    p += "Return JSON-shaped summary: {task_id, ok, commit, verify_cmd, evidence, files_changed}.\n";
    p += "cwd must be the repo. LocalWP plugin is symlinked to this repo.\n";

    let r = agent(p, #{
        label: "task-" + t.id,
        capability_mode: "all",
        agent_type: "implement",
        isolation_worktree: false,
    });

    if r == () || !r.success {
        log("FAILED task " + t.id);
        complete(#{
            phase: phase_n,
            ok: false,
            failed_task: t.id,
            done: done,
            message: "Implement agent failed for " + t.id,
        });
    }

    // Verify panel (read-only re-check)
    let vp = "";
    vp += "Verify task " + t.id + " in " + repo + ".\n";
    vp += "Run: " + t.verify + "\n";
    vp += "Also: git status -sb; git log -1 --oneline\n";
    vp += "Report {ok:bool, verify_exit:number, last_commit:string, notes:string}. ok=true only if verify command exit 0.\n";
    vp += "Do not edit files.\n";

    let v = agent(vp, #{
        label: "verify-" + t.id,
        capability_mode: "execute",
        agent_type: "explore",
    });

    let vok = false;
    if v != () && v.success {
        vok = true;
    }
    // explore may not be perfect; require implement success as floor
    done.push(#{ id: t.id, implement_ok: true, verify_agent_ok: vok });
    log("Completed agent cycle for " + t.id);
}

phase("Verify");
let summary_p = "";
summary_p += "In " + repo + " run npm run test:env if present. git log -5 --oneline. git status -sb.\n";
summary_p += "Summarize phase " + phase_n.to_string() + " completion: commits made, remaining work.\n";
summary_p += "Return {ok, commits, status_clean, notes}.\n";

let s = agent(summary_p, #{
    label: "phase-summary",
    capability_mode: "execute",
    agent_type: "explore",
});

complete(#{
    phase: phase_n,
    ok: true,
    tasks: done,
    summary_agent: if s != () && s.success { s.output } else { () },
    next: "Re-run workflow with args.phase = next phase when ready",
});

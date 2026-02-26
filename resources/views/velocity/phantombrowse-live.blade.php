<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>PhantomBrowse Live Command Viewer</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 16px; background: #111827; color: #f9fafb; }
        .row { display: flex; gap: 16px; margin-bottom: 12px; align-items: center; flex-wrap: wrap; }
        .card { background: #1f2937; border: 1px solid #374151; border-radius: 10px; padding: 12px; margin-bottom: 12px; }
        .label { color: #9ca3af; font-size: 12px; }
        .mono { font-family: Consolas, Menlo, monospace; font-size: 12px; word-break: break-all; }
        .btn { background: #2563eb; color: #fff; border: none; border-radius: 8px; padding: 8px 12px; cursor: pointer; }
        .btn:disabled { opacity: 0.6; cursor: default; }
        #frameView { width: 100%; max-height: 72vh; object-fit: contain; background: #000; border: 1px solid #374151; border-radius: 10px; }
        .input { background: #111827; color: #fff; border: 1px solid #374151; border-radius: 8px; padding: 8px; min-width: 300px; }
        .input.small { min-width: 130px; width: 130px; }
        .error { color: #fca5a5; }
        .ok { color: #86efac; }
        .input-grow { flex: 1 1 520px; min-width: 520px; }
    </style>
</head>
<body>
    <h2>PhantomBrowse Live Command Viewer</h2>

    <div class="card">
        <div class="label">Run SubmitTaskCommand</div>
        <div class="row" style="margin-top:8px; margin-bottom:0;">
            <input id="apiTokenInput" class="input mono" value="{{ $defaultApiToken }}" placeholder="API key token" />
            <input id="providerProfileInput" class="input small mono" value="{{ $defaultProviderProfileId }}" placeholder="provider-profile-id" />
            <input id="maxStepsInput" class="input small mono" value="{{ $defaultMaxSteps }}" placeholder="max-steps" />
        </div>
        <div class="row" style="margin-top:8px; margin-bottom:0;">
            <input id="llmInput" class="input small mono" value="" placeholder="llm (optional)" />
            <input id="sessionIdInput" class="input mono" value="" placeholder="session-id (optional)" />
            <input id="profileIdInput" class="input mono" value="" placeholder="profile-id (optional)" />
            <input id="startUrlInput" class="input input-grow mono" value="" placeholder="start-url (optional)" />
        </div>
        <div class="row" style="margin-top:8px; margin-bottom:0;">
            <input id="taskInput" class="input input-grow" value="{{ $defaultTaskPrompt }}" placeholder="Task prompt" />
        </div>
        <div class="row" style="margin-top:8px; margin-bottom:0;">
            <label class="mono"><input type="checkbox" id="waitInput" style="width:auto" /> --wait</label>
            <input id="timeoutInput" class="input small mono" value="300" placeholder="timeout" />
            <input id="pollInput" class="input small mono" value="2" placeholder="poll" />
            <label class="mono"><input type="checkbox" id="dispatchQueuedInput" style="width:auto" /> --dispatch-queued</label>
            <button id="startJobBtn" class="btn">Run Command</button>
            <div id="startJobStatus" class="mono"></div>
        </div>
    </div>

    <div class="row">
        <div><span class="label">Session ID</span><div class="mono">{{ $sessionId }}</div></div>
        <div><span class="label">Status</span><div id="status" class="mono">{{ $attach?->status ?? 'unknown' }}</div></div>
        <div><span class="label">Relay</span><div id="relayStatus" class="mono">connecting</div></div>
        <div><span class="label">Frames</span><div id="frameCount" class="mono">0</div></div>
        <button id="refreshBtn" class="btn">Refresh Attach Info</button>
    </div>

    <div class="card">
        <div class="label">Live Relay WebSocket URL (auto-managed)</div>
        <div class="row" style="margin-top:8px; margin-bottom:0;">
            <input id="relayWsUrl" class="input mono" value="{{ $relayWsUrl }}" readonly />
            <button id="connectRelayBtn" class="btn">Connect Relay</button>
        </div>
    </div>

    <div class="card">
        <img id="frameView" alt="Live browser frame" />
    </div>

    @if (!empty($error))
        <div class="card error">{{ $error }}</div>
    @endif

    <div class="card">
        <div class="label">Browser WS Endpoint</div>
        <div id="ws" class="mono">{{ $attach?->browserWSEndpoint ?? 'n/a' }}</div>
    </div>

    <div class="card">
        <div class="label">CDP Version URL</div>
        <div id="cdpVersion" class="mono">{{ $attach?->cdpEndpoints['version'] ?? 'n/a' }}</div>
        <div class="label" style="margin-top: 8px;">CDP List URL</div>
        <div id="cdpList" class="mono">{{ $attach?->cdpEndpoints['list'] ?? 'n/a' }}</div>
    </div>

    <div class="card">
        <div class="label">Inspector URL (CDP)</div>
        @if (!empty($inspectorUrl))
            <a id="inspectorUrl" class="mono" href="{{ $inspectorUrl }}" target="_blank" rel="noopener noreferrer">{{ $inspectorUrl }}</a>
        @else
            <div id="inspectorUrl" class="mono">n/a</div>
        @endif
    </div>

    @php($attachInfoUrl = route('phantombrowse.live.attach-info', ['sessionId' => $sessionId, 'token' => $accessToken]))
    @php($startJobUrl = route('phantombrowse.live.start-job', ['token' => $accessToken]))
    <script>
        const attachInfoUrl = '{{ $attachInfoUrl }}';
        const startJobUrl = '{{ $startJobUrl }}';
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const statusEl = document.getElementById('status');
        const relayStatusEl = document.getElementById('relayStatus');
        const frameCountEl = document.getElementById('frameCount');
        const wsEl = document.getElementById('ws');
        const cdpVersionEl = document.getElementById('cdpVersion');
        const cdpListEl = document.getElementById('cdpList');
        const inspectorUrlEl = document.getElementById('inspectorUrl');
        const refreshBtn = document.getElementById('refreshBtn');
        const relayWsInput = document.getElementById('relayWsUrl');
        const connectRelayBtn = document.getElementById('connectRelayBtn');
        const frameView = document.getElementById('frameView');
        const startJobBtn = document.getElementById('startJobBtn');
        const startJobStatus = document.getElementById('startJobStatus');
        const apiTokenInput = document.getElementById('apiTokenInput');
        const taskInput = document.getElementById('taskInput');
        const providerProfileInput = document.getElementById('providerProfileInput');
        const maxStepsInput = document.getElementById('maxStepsInput');
        const llmInput = document.getElementById('llmInput');
        const sessionIdInput = document.getElementById('sessionIdInput');
        const profileIdInput = document.getElementById('profileIdInput');
        const startUrlInput = document.getElementById('startUrlInput');
        const waitInput = document.getElementById('waitInput');
        const timeoutInput = document.getElementById('timeoutInput');
        const pollInput = document.getElementById('pollInput');
        const dispatchQueuedInput = document.getElementById('dispatchQueuedInput');

        let relaySocket = null;
        let frameCount = 0;

        async function refreshAttachInfo() {
            refreshBtn.disabled = true;
            try {
                const response = await fetch(attachInfoUrl, { credentials: 'same-origin' });
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }

                const payload = await response.json();
                statusEl.textContent = payload.status ?? 'unknown';
                wsEl.textContent = payload.browserWSEndpoint ?? 'n/a';
                cdpVersionEl.textContent = payload.cdpEndpoints?.version ?? 'n/a';
                cdpListEl.textContent = payload.cdpEndpoints?.list ?? 'n/a';
                if (inspectorUrlEl?.tagName === 'A') {
                    inspectorUrlEl.textContent = payload.inspectorUrl ?? 'n/a';
                    if (payload.inspectorUrl) {
                        inspectorUrlEl.href = payload.inspectorUrl;
                    }
                } else {
                    inspectorUrlEl.textContent = payload.inspectorUrl ?? 'n/a';
                }
            } catch (error) {
                statusEl.textContent = 'error';
                statusEl.classList.add('error');
                console.error(error);
            } finally {
                refreshBtn.disabled = false;
            }
        }

        function disconnectRelay() {
            if (relaySocket) {
                relaySocket.close();
                relaySocket = null;
            }
        }

        function connectRelay() {
            disconnectRelay();

            const relayUrl = relayWsInput.value.trim();
            if (!relayUrl) {
                relayStatusEl.textContent = 'missing relay URL';
                relayStatusEl.className = 'mono error';
                return;
            }

            try {
                relaySocket = new WebSocket(relayUrl);
                relayStatusEl.textContent = 'connecting';
                relayStatusEl.className = 'mono';

                relaySocket.onopen = () => {
                    relayStatusEl.textContent = 'connected';
                    relayStatusEl.className = 'mono ok';
                };

                relaySocket.onclose = () => {
                    relayStatusEl.textContent = 'disconnected';
                    relayStatusEl.className = 'mono error';
                };

                relaySocket.onerror = () => {
                    relayStatusEl.textContent = 'error';
                    relayStatusEl.className = 'mono error';
                };

                relaySocket.onmessage = (event) => {
                    let payload;
                    try {
                        payload = JSON.parse(event.data);
                    } catch {
                        return;
                    }

                    if (payload.type === 'frame' && payload.base64) {
                        frameView.src = 'data:image/jpeg;base64,' + payload.base64;
                        frameCount += 1;
                        frameCountEl.textContent = String(frameCount);
                    }
                };
            } catch (error) {
                relayStatusEl.textContent = 'error';
                relayStatusEl.className = 'mono error';
                console.error(error);
            }
        }

        async function startLiveJob() {
            startJobBtn.disabled = true;
            startJobStatus.textContent = 'running submit command...';
            startJobStatus.className = 'mono';

            try {
                const providerProfileRaw = providerProfileInput.value.trim();
                const response = await fetch(startJobUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        apiKeyToken: apiTokenInput.value.trim(),
                        task: taskInput.value.trim(),
                        sessionId: sessionIdInput.value.trim() || null,
                        profileId: profileIdInput.value.trim() || null,
                        providerProfileId: providerProfileRaw !== '' ? Number(providerProfileRaw) : null,
                        llm: llmInput.value.trim() || null,
                        startUrl: startUrlInput.value.trim() || null,
                        maxSteps: Number(maxStepsInput.value || 12),
                        wait: Boolean(waitInput.checked),
                        timeout: Number(timeoutInput.value || 300),
                        poll: Number(pollInput.value || 2),
                        dispatchQueued: Boolean(dispatchQueuedInput.checked),
                    }),
                });

                const payload = await response.json();
                if (!response.ok || !payload.ok) {
                    throw new Error(payload.message || ('HTTP ' + response.status));
                }

                startJobStatus.textContent = 'command finished, opening viewer...';
                startJobStatus.className = 'mono ok';
                window.location.href = payload.viewerUrl;
            } catch (error) {
                startJobStatus.textContent = String(error.message || error);
                startJobStatus.className = 'mono error';
            } finally {
                startJobBtn.disabled = false;
            }
        }

        refreshBtn.addEventListener('click', refreshAttachInfo);
        connectRelayBtn.addEventListener('click', connectRelay);
        startJobBtn.addEventListener('click', startLiveJob);
        window.setInterval(refreshAttachInfo, 10000);
        refreshAttachInfo();
        connectRelay();
    </script>
</body>
</html>

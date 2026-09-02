const { execFile, execFileSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { performance } = require('node:perf_hooks');

const repoRoot = path.resolve(__dirname, '../..');
const serverDir = path.join(repoRoot, 'apps/server');
const baseUrl = requiredUrl('WAYFINDR_BASE_URL', ['http:', 'https:']);
const reverbUrl = requiredUrl('WAYFINDR_REVERB_URL', ['ws:', 'wss:']);
const reverbAppKey = requiredEnv('WAYFINDR_REVERB_APP_KEY');
const reverbPid = positiveIntegerEnv('WAYFINDR_REVERB_PID');
const phpBinary = requiredEnv('WAYFINDR_CAPACITY_PHP_BINARY');
const outputPath = process.env.WAYFINDR_CAPACITY_OUTPUT || '';
const steps = capacitySteps(process.env.WAYFINDR_CAPACITY_STEPS || '10,25,50,100,200');
const stageEvents = positiveIntegerEnv('WAYFINDR_CAPACITY_STAGE_EVENTS', 3);
const holdSeconds = positiveIntegerEnv('WAYFINDR_CAPACITY_HOLD_SECONDS', 90);
const holdEventSeconds = positiveIntegerEnv('WAYFINDR_CAPACITY_HOLD_EVENT_SECONDS', 15);
const connectConcurrency = positiveIntegerEnv('WAYFINDR_CAPACITY_CONNECT_CONCURRENCY', 10);
const timeoutMs = positiveIntegerEnv('WAYFINDR_CAPACITY_TIMEOUT_MS', 10000);
const agentPassword = process.env.WAYFINDR_CAPACITY_AGENT_PASSWORD || 'password';
const allowShortHold = process.env.WAYFINDR_CAPACITY_ALLOW_SHORT_HOLD === '1';
const allowDirty = process.env.WAYFINDR_CAPACITY_ALLOW_DIRTY === '1';
const metadata = {
  database_placement: requiredEnv('WAYFINDR_CAPACITY_DATABASE_PLACEMENT'),
  redis_placement: requiredEnv('WAYFINDR_CAPACITY_REDIS_PLACEMENT'),
  reverse_proxy: requiredEnv('WAYFINDR_CAPACITY_REVERSE_PROXY'),
  php_workers: positiveIntegerEnv('WAYFINDR_CAPACITY_PHP_WORKERS'),
  queue_workers: nonNegativeIntegerEnv('WAYFINDR_CAPACITY_QUEUE_WORKERS'),
  reverb_processes: positiveIntegerEnv('WAYFINDR_CAPACITY_REVERB_PROCESSES'),
  reverb_configuration: requiredEnv('WAYFINDR_CAPACITY_REVERB_CONFIGURATION'),
  reverb_ping_interval_seconds: positiveIntegerEnv('WAYFINDR_CAPACITY_REVERB_PING_INTERVAL_SECONDS'),
};

let eventSequence = 0;

async function run() {
  verifyReverbProcess(reverbPid);
  const runtime = runtimeEnvironment();

  const monitor = new ProcessMonitor(reverbPid);
  const clients = [];
  const attemptedClients = [];
  const stages = [];
  let supportCode = '';
  let nextAgentIndex = 0;
  let highestStable = 0;
  let firstFailure = null;
  let hold = null;

  await monitor.start();

  try {
    for (const target of steps) {
      monitor.setContext(`ramp-${target}`, clients.length);
      const previousStable = highestStable;
      const disconnectsBefore = sum(attemptedClients, (client) => client.disconnects);
      const reconnectsBefore = sum(attemptedClients, (client) => client.reconnectAttempts);
      const websocketErrorsBefore = sum(attemptedClients, (client) => client.websocketErrors);
      await monitor.sample();
      const resourceStart = monitor.samples.length;
      const additions = [];

      if (clients.length === 0) {
        const first = await createAgent(nextAgentIndex, null);
        nextAgentIndex += 1;
        supportCode = await first.client.discoverConversation();
        await first.client.prepare(supportCode);
        first.connection = await first.client.connect(false);
        clients.push(first.client);
        attemptedClients.push(first.client);
        additions.push(first);
      }

      const needed = Math.max(0, target - clients.length);
      const indexes = Array.from({ length: needed }, () => nextAgentIndex++);
      const outcomes = await mapLimit(indexes, connectConcurrency, async (index) => {
        try {
          return await createAgent(index, supportCode);
        } catch (error) {
          return {
            error: safeError(error),
            client: error.client || null,
            login_ms: error.client?.loginMs ?? null,
          };
        }
      });

      for (const outcome of outcomes) {
        additions.push(outcome);

        if (outcome.client) {
          attemptedClients.push(outcome.client);

          if (!outcome.error) {
            clients.push(outcome.client);
          } else {
            outcome.client.close();
          }
        }
      }

      monitor.setContext(`ramp-${target}`, clients.length);
      const stageDeliveries = [];
      const attemptFailures = additions.filter((result) => result.error);
      const loginFailures = attemptFailures.filter((result) => !result.client?.loginSucceeded);
      const connectionFailures = attemptFailures.filter((result) => result.client?.loginSucceeded);

      if (attemptFailures.length === 0 && clients.length === target) {
        for (let index = 0; index < stageEvents; index += 1) {
          stageDeliveries.push(await broadcastTyping(clients, clients[0]));
        }
      }

      await monitor.sample();

      const deliveryFailures = stageDeliveries.reduce((total, delivery) => total + delivery.missed, 0);
      const triggerFailures = stageDeliveries.filter((delivery) => delivery.trigger_error);
      const stageDisconnects = sum(attemptedClients, (client) => client.disconnects) - disconnectsBefore;
      const stageReconnects = sum(attemptedClients, (client) => client.reconnectAttempts) - reconnectsBefore;
      const stageWebsocketErrors = sum(attemptedClients, (client) => client.websocketErrors) - websocketErrorsBefore;
      const stable = attemptFailures.length === 0
        && clients.length === target
        && stageDeliveries.length === stageEvents
        && deliveryFailures === 0
        && triggerFailures.length === 0
        && stageDisconnects === 0
        && stageReconnects === 0
        && stageWebsocketErrors === 0;

      stages.push({
        target_agents: target,
        stable,
        signed_in_sessions: clients.length,
        login_attempts: additions.length,
        login_successes: additions.filter((result) => result.client?.loginSucceeded).length,
        login_failures: loginFailures.length,
        connection_attempts: additions.filter((result) => result.client?.loginSucceeded).length,
        subscription_successes: additions.filter((result) => result.connection).length,
        connection_failures: connectionFailures.length,
        attempt_failure_kinds: countBy(attemptFailures.map((failure) => failure.error?.name || 'Error')),
        login_ms: distribution(additions.map((result) => result.login_ms)),
        websocket_ms: distribution(additions.map((result) => result.connection?.websocket_ms)),
        subscription_ms: distribution(additions.map((result) => result.connection?.subscription_ms)),
        ready_ms: distribution(additions.map((result) => result.connection?.ready_ms)),
        broadcasts: stageDeliveries.length,
        expected_deliveries: stageDeliveries.reduce((total, delivery) => total + delivery.expected, 0),
        delivered: stageDeliveries.reduce((total, delivery) => total + delivery.delivered, 0),
        missed_deliveries: deliveryFailures,
        trigger_failures: triggerFailures.length,
        trigger_failure_kinds: countBy(triggerFailures.map((delivery) => delivery.trigger_error?.name || 'Error')),
        broadcast_http_ms: distribution(stageDeliveries.map((delivery) => delivery.trigger_http_ms)),
        delivery_ms: distribution(stageDeliveries.flatMap((delivery) => delivery.latencies_ms)),
        disconnects: stageDisconnects,
        reconnect_attempts: stageReconnects,
        websocket_errors: stageWebsocketErrors,
        cumulative_disconnects: sum(attemptedClients, (client) => client.disconnects),
        cumulative_reconnect_attempts: sum(attemptedClients, (client) => client.reconnectAttempts),
        cumulative_reconnect_successes: sum(attemptedClients, (client) => client.reconnectSuccesses),
        cumulative_websocket_errors: sum(attemptedClients, (client) => client.websocketErrors),
        resources: resourceSummary(monitor.samples.slice(resourceStart)),
      });

      if (stable) {
        highestStable = target;

        continue;
      }

      firstFailure = {
        target_agents: target,
        kind: loginFailures.length > 0
          ? 'login'
          : (connectionFailures.length > 0
            ? 'connection_or_subscription'
            : (triggerFailures.length > 0
              ? 'broadcast_trigger'
              : (deliveryFailures > 0 ? 'broadcast_delivery' : 'transport_instability'))),
      };

      while (clients.length > previousStable) {
        clients.pop().close();
      }

      break;
    }

    if (highestStable > 0) {
      monitor.setContext(`hold-${highestStable}`, clients.length);
      const disconnectsBefore = sum(clients, (client) => client.disconnects);
      const reconnectsBefore = sum(clients, (client) => client.reconnectAttempts);
      const reconnectSuccessesBefore = sum(clients, (client) => client.reconnectSuccesses);
      const serverPingsBefore = sum(clients, (client) => client.serverPings);
      const websocketErrorsBefore = sum(clients, (client) => client.websocketErrors);
      await monitor.sample();
      const resourceStart = monitor.samples.length;
      const holdStartedAt = performance.now();
      const deliveries = [];

      for (let dueSeconds = 0; dueSeconds < holdSeconds; dueSeconds += holdEventSeconds) {
        const waitMs = (holdStartedAt + dueSeconds * 1000) - performance.now();

        if (waitMs > 0) {
          await delay(waitMs);
        }

        deliveries.push(await broadcastTyping(clients, clients[0]));
      }

      const remainingMs = (holdStartedAt + holdSeconds * 1000) - performance.now();

      if (remainingMs > 0) {
        await delay(remainingMs);
      }

      const holdFinishedAt = performance.now();
      clients.forEach((client) => client.pauseKeepalive());
      const pendingKeepalives = clients
        .map((client) => client.pendingKeepalive)
        .filter((pending) => (
          pending
          && pending.sentAt >= holdStartedAt
          && pending.sentAt <= holdFinishedAt
        ));
      const keepaliveSettleStartedAt = performance.now();
      await Promise.all(pendingKeepalives.map((pending) => pending.settled));
      const keepaliveSettleMs = performance.now() - keepaliveSettleStartedAt;
      await monitor.sample();
      const perClientKeepalives = clients.map((client) => ({
        sends: client.keepaliveSentAt.filter((sentAt) => sentAt >= holdStartedAt && sentAt <= holdFinishedAt).length,
        acknowledgements: client.keepaliveAcks.filter((acknowledgement) => (
          acknowledgement.sentAt >= holdStartedAt
          && acknowledgement.sentAt <= holdFinishedAt
        )),
        timeouts: client.keepaliveTimeouts.filter((timeout) => (
          timeout.sentAt >= holdStartedAt && timeout.sentAt <= holdFinishedAt
        )).length,
      }));

      hold = {
        agents: highestStable,
        requested_seconds: holdSeconds,
        actual_seconds: round((holdFinishedAt - holdStartedAt) / 1000),
        broadcasts: deliveries.length,
        expected_deliveries: deliveries.reduce((total, delivery) => total + delivery.expected, 0),
        delivered: deliveries.reduce((total, delivery) => total + delivery.delivered, 0),
        missed_deliveries: deliveries.reduce((total, delivery) => total + delivery.missed, 0),
        trigger_failures: deliveries.filter((delivery) => delivery.trigger_error).length,
        trigger_failure_kinds: countBy(deliveries
          .filter((delivery) => delivery.trigger_error)
          .map((delivery) => delivery.trigger_error?.name || 'Error')),
        broadcast_http_ms: distribution(deliveries.map((delivery) => delivery.trigger_http_ms)),
        delivery_ms: distribution(deliveries.flatMap((delivery) => delivery.latencies_ms)),
        disconnects: sum(clients, (client) => client.disconnects) - disconnectsBefore,
        reconnect_attempts: sum(clients, (client) => client.reconnectAttempts) - reconnectsBefore,
        reconnect_successes: sum(clients, (client) => client.reconnectSuccesses) - reconnectSuccessesBefore,
        keepalives_sent: sum(perClientKeepalives, (client) => client.sends),
        keepalive_pongs: sum(perClientKeepalives, (client) => client.acknowledgements.length),
        unacknowledged_keepalives: sum(perClientKeepalives, (client) => (
          client.sends - client.acknowledgements.length
        )),
        keepalive_timeouts: sum(perClientKeepalives, (client) => client.timeouts),
        keepalive_settle_ms: round(keepaliveSettleMs),
        clients_sending_keepalives: perClientKeepalives.filter((client) => client.sends > 0).length,
        clients_receiving_keepalive_pongs: perClientKeepalives
          .filter((client) => client.acknowledgements.length > 0).length,
        keepalive_pong_ms: distribution(perClientKeepalives
          .flatMap((client) => client.acknowledgements.map((acknowledgement) => acknowledgement.latencyMs))),
        server_pings: sum(clients, (client) => client.serverPings) - serverPingsBefore,
        websocket_errors: sum(clients, (client) => client.websocketErrors) - websocketErrorsBefore,
        subscribed_at_end: clients.filter((client) => client.subscribed).length,
        resources: resourceSummary(monitor.samples.slice(resourceStart)),
      };
    }
  } finally {
    for (const client of clients) {
      client.close();
    }

    await monitor.stop();
  }

  const workingTreeCleanAtEnd = workingTreeStatus() === '';

  assert(
    allowDirty || workingTreeCleanAtEnd,
    'Capacity harness refuses to publish a report because the worktree became dirty during the measurement; discard the run and repeat from a clean revision.',
  );

  runtime.working_tree_clean_at_end = workingTreeCleanAtEnd;
  runtime.working_tree_clean = runtime.working_tree_clean_at_start && workingTreeCleanAtEnd;

  const report = {
    schema_version: 3,
    measured_at: new Date().toISOString(),
    environment: {
      ...runtime,
      observed_activity_timeout_seconds: distribution(clients.map((client) => client.activityTimeoutSeconds)),
    },
    workload: {
      distinct_seeded_agent_accounts: true,
      private_channel: 'one seeded conversation channel shared by every agent',
      event: 'conversation.typing.updated through the authenticated dashboard endpoint',
      ramp_targets: steps,
      stage_broadcasts: stageEvents,
      hold_seconds: holdSeconds,
      hold_event_seconds: holdEventSeconds,
      connect_concurrency: connectConcurrency,
      report_contains_credentials_or_event_payloads: false,
    },
    result: {
      highest_stable_agents: highestStable,
      test_ceiling_reached: highestStable === steps.at(-1),
      first_failure: firstFailure,
    },
    stages,
    hold,
    verification: {
      every_stable_stage_delivered_every_event: stages
        .filter((stage) => stage.stable)
        .every((stage) => stage.missed_deliveries === 0 && stage.delivered === stage.expected_deliveries),
      qualifying_keepalive_hold: Boolean(hold && hold.actual_seconds >= 70),
      hold_kept_every_client_subscribed: Boolean(hold && hold.subscribed_at_end === hold.agents),
      hold_delivered_every_event: Boolean(
        hold
        && hold.trigger_failures === 0
        && hold.missed_deliveries === 0
        && hold.delivered === hold.expected_deliveries,
      ),
      hold_had_no_disconnects: Boolean(hold && hold.disconnects === 0),
      hold_had_no_reconnects: Boolean(hold && hold.reconnect_attempts === 0),
      hold_had_no_websocket_errors: Boolean(hold && hold.websocket_errors === 0),
      application_keepalives_exercised: Boolean(
        hold
        && hold.keepalive_timeouts === 0
        && hold.unacknowledged_keepalives === 0
        && hold.keepalives_sent === hold.keepalive_pongs
        && hold.clients_sending_keepalives === hold.agents
        && hold.clients_receiving_keepalive_pongs === hold.agents,
      ),
      no_disconnects: sum(attemptedClients, (client) => client.disconnects) === 0,
      no_reconnect_attempts: sum(attemptedClients, (client) => client.reconnectAttempts) === 0,
      no_websocket_errors: sum(attemptedClients, (client) => client.websocketErrors) === 0,
      no_resource_sampling_errors: monitor.errors.length === 0,
    },
    resource_sampling_errors: monitor.errors,
    process_samples: monitor.samples,
  };

  writeReport(report);

  assert(report.result.highest_stable_agents > 0, 'No ramp stage completed successfully.');
  assert(report.verification.no_resource_sampling_errors, 'One or more Reverb resource samples failed.');
  assert(report.verification.hold_kept_every_client_subscribed, 'Not every client remained subscribed through the hold.');
  assert(report.verification.hold_delivered_every_event, 'The hold missed one or more expected deliveries.');
  assert(report.verification.hold_had_no_disconnects, 'One or more clients disconnected during the hold.');
  assert(report.verification.hold_had_no_reconnects, 'One or more clients reconnected during the hold.');
  assert(report.verification.hold_had_no_websocket_errors, 'One or more clients reported a WebSocket error during the hold.');
  assert(report.verification.no_disconnects, 'One or more clients disconnected during the measurement.');
  assert(report.verification.no_reconnect_attempts, 'One or more clients attempted to reconnect during the measurement.');
  assert(report.verification.no_websocket_errors, 'One or more clients reported a WebSocket error during the measurement.');

  if (!allowShortHold) {
    assert(report.verification.qualifying_keepalive_hold, 'Hold did not run for at least 70 seconds.');
    assert(report.verification.application_keepalives_exercised, 'The hold did not exercise Pusher application keepalives.');
  }
}

async function createAgent(index, supportCode) {
  const client = new CapacityAgent(index, supportCode);

  try {
    const loginMs = await client.login();

    if (supportCode) {
      await client.prepare(supportCode);
    }

    const connection = supportCode ? await client.connect(false) : null;

    return { client, login_ms: loginMs, connection };
  } catch (error) {
    error.client = client;
    throw error;
  }
}

class CapacityAgent {
  constructor(index, supportCode) {
    this.index = index;
    this.supportCode = supportCode;
    this.session = new HttpSession(baseUrl);
    this.loginSucceeded = false;
    this.loginMs = null;
    this.csrf = '';
    this.socket = null;
    this.subscribed = false;
    this.closing = false;
    this.hasSubscribed = false;
    this.keepaliveTimer = null;
    this.pendingKeepalive = null;
    this.reconnectTimer = null;
    this.waiters = new Set();
    this.disconnects = 0;
    this.reconnectAttempts = 0;
    this.reconnectSuccesses = 0;
    this.websocketErrors = 0;
    this.keepalivesSent = 0;
    this.keepalivePongs = 0;
    this.keepaliveSentAt = [];
    this.keepaliveAcks = [];
    this.keepaliveTimeouts = [];
    this.serverPings = 0;
    this.activityTimeoutSeconds = null;
  }

  async login() {
    const startedAt = performance.now();
    const loginPage = await this.session.request('/login');
    assert(loginPage.ok, `Agent ${this.index} login page answered ${loginPage.status}.`);
    const token = extractInputToken(loginPage.body);
    const response = await this.session.request('/login', {
      method: 'POST',
      form: {
        _token: token,
        email: `desk-agent-${this.index}@example.test`,
        password: agentPassword,
      },
    });

    assert(response.ok && new URL(response.url).pathname !== '/login', `Agent ${this.index} could not sign in.`);
    assert(response.body.includes('Measurement Desk'), `Agent ${this.index} did not land in the disposable measurement desk.`);

    this.loginSucceeded = true;
    this.loginMs = round(performance.now() - startedAt);

    return this.loginMs;
  }

  async discoverConversation() {
    const response = await this.session.request('/dashboard/conversations?conversation_filter=all');
    assert(response.ok, `Conversation queue answered ${response.status}.`);
    const match = response.body.match(/\/dashboard\/conversations\/(WF-[A-Z0-9-]+)/);
    assert(match, 'The measurement desk exposed no conversation to subscribe to.');

    return match[1];
  }

  async prepare(supportCode) {
    this.supportCode = supportCode;
    const response = await this.session.request(`/dashboard/conversations/${encodeURIComponent(supportCode)}`);
    assert(
      response.ok && new URL(response.url).pathname !== '/login',
      `Agent ${this.index} could not open the seeded conversation (HTTP ${response.status} at ${new URL(response.url).pathname}).`,
    );
    this.csrf = extractMetaToken(response.body);
  }

  async connect(isReconnect) {
    const startedAt = performance.now();
    this.closing = false;

    if (isReconnect) {
      this.reconnectAttempts += 1;
    }

    const socketUrl = new URL(`${reverbUrl.toString().replace(/\/$/, '')}/app/${encodeURIComponent(reverbAppKey)}`);
    socketUrl.searchParams.set('protocol', '7');
    socketUrl.searchParams.set('client', 'wayfindr-capacity');
    socketUrl.searchParams.set('version', '1.0');
    socketUrl.searchParams.set('flash', 'false');

    return new Promise((resolve, reject) => {
      let establishedAt = null;
      let finished = false;
      const socket = new WebSocket(socketUrl);
      this.socket = socket;

      const timer = setTimeout(() => {
        fail(new Error('WebSocket subscription timed out.'));
      }, timeoutMs);

      const fail = (error) => {
        if (finished) {
          return;
        }

        finished = true;
        clearTimeout(timer);
        reject(error);

        try {
          socket.close();
        } catch (closeError) {
          // The failed connection is already unusable.
        }
      };

      socket.addEventListener('error', () => {
        this.websocketErrors += 1;

        if (!finished) {
          fail(new Error('WebSocket connection failed.'));
        }
      });

      socket.addEventListener('close', () => {
        this.stopKeepalive();
        this.subscribed = false;

        if (this.closing) {
          return;
        }

        this.disconnects += 1;

        if (!finished) {
          fail(new Error('WebSocket closed before subscription completed.'));

          return;
        }

        if (this.hasSubscribed) {
          this.scheduleReconnect();
        }
      });

      socket.addEventListener('message', (message) => {
        let envelope;

        try {
          envelope = JSON.parse(String(message.data || ''));
        } catch (error) {
          this.websocketErrors += 1;

          if (!finished) {
            fail(new Error('Reverb returned a malformed Pusher envelope.'));
          }

          return;
        }

        if (envelope.event === 'pusher:ping') {
          this.serverPings += 1;
          socket.send(JSON.stringify({ event: 'pusher:pong', data: {} }));

          return;
        }

        if (envelope.event === 'pusher:pong') {
          if (this.pendingKeepalive && this.pendingKeepalive.socket === socket) {
            const receivedAt = performance.now();
            const pending = this.pendingKeepalive;
            clearTimeout(pending.timer);
            this.keepalivePongs += 1;
            this.keepaliveAcks.push({
              sentAt: pending.sentAt,
              receivedAt,
              latencyMs: round(receivedAt - pending.sentAt),
            });
            this.pendingKeepalive = null;
            pending.settle();
          }

          return;
        }

        if (envelope.event === 'pusher:error') {
          this.websocketErrors += 1;
          fail(new Error('Reverb returned a Pusher protocol error.'));

          return;
        }

        if (envelope.event === 'pusher:connection_established') {
          let established;

          try {
            established = parseEnvelopeData(envelope.data);
          } catch (error) {
            fail(new Error('Reverb returned an invalid connection envelope.'));

            return;
          }

          establishedAt = performance.now();
          this.activityTimeoutSeconds = Number(established.activity_timeout) || null;
          this.startKeepalive(this.activityTimeoutSeconds);
          this.authorize(established.socket_id)
            .then((auth) => {
              if (socket !== this.socket || socket.readyState !== WebSocket.OPEN) {
                throw new Error('WebSocket closed before channel authorization completed.');
              }

              socket.send(JSON.stringify({
                event: 'pusher:subscribe',
                data: {
                  auth,
                  channel: this.channelName(),
                },
              }));
            })
            .catch(fail);

          return;
        }

        if (envelope.event === 'pusher_internal:subscription_succeeded') {
          if (finished) {
            return;
          }

          finished = true;
          clearTimeout(timer);
          this.subscribed = true;
          this.hasSubscribed = true;

          if (isReconnect) {
            this.reconnectSuccesses += 1;
          }

          const finishedAt = performance.now();
          resolve({
            websocket_ms: round((establishedAt || finishedAt) - startedAt),
            subscription_ms: round(establishedAt ? finishedAt - establishedAt : null),
            ready_ms: round(finishedAt - startedAt),
            activity_timeout_seconds: this.activityTimeoutSeconds,
          });

          return;
        }

        if (envelope.event === 'conversation.typing.updated') {
          const payload = parseEnvelopeData(envelope.data);
          const state = payload?.agent_typing?.state || 'idle';

          for (const waiter of this.waiters) {
            if (waiter.state !== state) {
              continue;
            }

            clearTimeout(waiter.timer);
            this.waiters.delete(waiter);
            waiter.resolve(round(performance.now() - waiter.startedAt));
          }
        }
      });
    });
  }

  async authorize(socketId) {
    const response = await this.session.request('/broadcasting/auth', {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'X-CSRF-TOKEN': this.csrf,
        'X-Requested-With': 'XMLHttpRequest',
      },
      form: {
        socket_id: socketId,
        channel_name: this.channelName(),
      },
    });

    assert(response.ok, `Agent ${this.index} broadcast authorization answered ${response.status}.`);
    const body = JSON.parse(response.body);
    assert(typeof body.auth === 'string' && body.auth.length > 0, `Agent ${this.index} received no private-channel authorization.`);

    return body.auth;
  }

  channelName() {
    return `private-conversations.${this.supportCode}`;
  }

  waitForTyping(state, startedAt) {
    if (!this.subscribed) {
      return Promise.reject(new Error('Agent was not subscribed when a broadcast began.'));
    }

    return new Promise((resolve, reject) => {
      const waiter = {
        state,
        startedAt,
        resolve,
        reject,
        timer: null,
      };

      waiter.timer = setTimeout(() => {
        this.waiters.delete(waiter);
        reject(new Error('Broadcast delivery timed out.'));
      }, timeoutMs);
      this.waiters.add(waiter);
    });
  }

  async triggerTyping(state) {
    const startedAt = performance.now();
    const response = await this.session.request(`/dashboard/conversations/${encodeURIComponent(this.supportCode)}/typing`, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'X-CSRF-TOKEN': this.csrf,
        'X-Requested-With': 'XMLHttpRequest',
      },
      form: { is_typing: state ? '1' : '0' },
    });

    assert(response.ok, `Typing broadcast trigger answered ${response.status}.`);

    return round(performance.now() - startedAt);
  }

  startKeepalive(activityTimeoutSeconds) {
    this.stopKeepalive();
    const declared = Number(activityTimeoutSeconds);
    const seconds = Math.max(5, Math.min(25, (declared > 0 ? declared : 30) / 2));

    this.keepaliveTimer = setInterval(() => {
      if (!this.socket || this.socket.readyState !== WebSocket.OPEN) {
        this.stopKeepalive();

        return;
      }

      if (this.pendingKeepalive) {
        return;
      }

      const socket = this.socket;
      const sentAt = performance.now();

      try {
        socket.send(JSON.stringify({ event: 'pusher:ping', data: {} }));
      } catch (error) {
        this.websocketErrors += 1;
        socket.close();

        return;
      }

      this.keepalivesSent += 1;
      this.keepaliveSentAt.push(sentAt);
      let settle;
      const pending = {
        socket,
        sentAt,
        settled: new Promise((resolve) => {
          settle = resolve;
        }),
        settle: () => settle(),
        timer: setTimeout(() => {
          if (this.pendingKeepalive !== pending) {
            return;
          }

          this.pendingKeepalive = null;
          this.keepaliveTimeouts.push({
            sentAt,
            timedOutAt: performance.now(),
          });
          this.websocketErrors += 1;
          pending.settle();
          socket.close(4000, 'Pusher keepalive timed out');
        }, timeoutMs),
      };
      this.pendingKeepalive = pending;
    }, seconds * 1000);
  }

  stopKeepalive() {
    this.pauseKeepalive();

    if (this.pendingKeepalive) {
      const pending = this.pendingKeepalive;
      clearTimeout(pending.timer);
      this.pendingKeepalive = null;
      pending.settle();
    }
  }

  pauseKeepalive() {
    if (this.keepaliveTimer) {
      clearInterval(this.keepaliveTimer);
      this.keepaliveTimer = null;
    }
  }

  scheduleReconnect() {
    if (this.closing || this.reconnectTimer) {
      return;
    }

    this.reconnectTimer = setTimeout(() => {
      this.reconnectTimer = null;
      this.connect(true).catch(() => this.scheduleReconnect());
    }, 250);
  }

  close() {
    this.closing = true;
    this.stopKeepalive();

    if (this.reconnectTimer) {
      clearTimeout(this.reconnectTimer);
      this.reconnectTimer = null;
    }

    for (const waiter of this.waiters) {
      clearTimeout(waiter.timer);
      waiter.reject(new Error('Agent connection closed.'));
    }

    this.waiters.clear();

    if (this.socket && this.socket.readyState < WebSocket.CLOSING) {
      this.socket.close(1000, 'capacity measurement complete');
    }
  }
}

class HttpSession {
  constructor(origin) {
    this.origin = origin;
    this.cookiesByOrigin = new Map();
  }

  async request(relative, options = {}) {
    let url = new URL(relative, this.origin);
    let method = options.method || 'GET';
    let form = options.form || null;
    let redirects = 0;

    while (true) {
      this.assertSafeUrl(url);
      const headers = { ...(options.headers || {}) };
      const cookies = this.cookiesByOrigin.get(url.origin) || new Map();
      const cookie = Array.from(cookies.entries()).map(([name, value]) => `${name}=${value}`).join('; ');

      if (cookie) {
        headers.Cookie = cookie;
      }

      let body;

      if (form && method !== 'GET' && method !== 'HEAD') {
        body = new URLSearchParams(form);
        headers['Content-Type'] = 'application/x-www-form-urlencoded;charset=UTF-8';
      }

      const response = await fetch(url, {
        method,
        headers,
        body,
        redirect: 'manual',
        signal: AbortSignal.timeout(timeoutMs),
      });

      this.storeCookies(url.origin, response.headers);

      if ([301, 302, 303, 307, 308].includes(response.status) && redirects < 5) {
        const location = response.headers.get('location');

        if (!location) {
          throw new Error('HTTP redirect contained no location.');
        }

        redirects += 1;
        const redirectUrl = new URL(location, url);
        this.assertSafeUrl(redirectUrl);
        url = redirectUrl;

        if ([301, 302, 303].includes(response.status)) {
          method = 'GET';
          form = null;
        }

        continue;
      }

      return {
        ok: response.ok,
        status: response.status,
        url: response.url,
        body: await response.text(),
      };
    }
  }

  assertSafeUrl(url) {
    assertLoopback(url, 'HTTP redirect');
    assert(
      url.origin === this.origin.origin,
      `HTTP requests must stay on the configured loopback origin ${this.origin.origin}; refused ${url.origin}.`,
    );
  }

  storeCookies(origin, headers) {
    const cookies = this.cookiesByOrigin.get(origin) || new Map();
    const values = typeof headers.getSetCookie === 'function' ? headers.getSetCookie() : [];

    for (const value of values) {
      const pair = value.split(';', 1)[0];
      const separator = pair.indexOf('=');

      if (separator <= 0) {
        continue;
      }

      const name = pair.slice(0, separator);
      const cookieValue = pair.slice(separator + 1);

      if (/max-age=0/i.test(value)) {
        cookies.delete(name);
      } else {
        cookies.set(name, cookieValue);
      }
    }

    this.cookiesByOrigin.set(origin, cookies);
  }
}

class ProcessMonitor {
  constructor(pid) {
    this.pid = pid;
    this.phase = 'idle';
    this.activeClients = 0;
    this.samples = [];
    this.timer = null;
    this.pending = null;
    this.stopping = false;
    this.errors = [];
    this.startedAt = performance.now();
    this.previousSnapshot = null;
    this.sampleChain = Promise.resolve();
    this.clockTicksPerSecond = process.platform === 'linux'
      ? Number(command('getconf', ['CLK_TCK'], repoRoot))
      : null;
    this.pageSize = process.platform === 'linux'
      ? Number(command('getconf', ['PAGESIZE'], repoRoot))
      : null;

    if (process.platform === 'linux') {
      assert(Number.isFinite(this.clockTicksPerSecond) && this.clockTicksPerSecond > 0, 'Could not determine Linux clock ticks per second.');
      assert(Number.isFinite(this.pageSize) && this.pageSize > 0, 'Could not determine Linux memory page size.');
    }
  }

  async start() {
    await this.sample();
    this.schedule();
  }

  schedule() {
    this.timer = setTimeout(() => {
      this.timer = null;
      this.pending = this.sample()
        .catch((error) => this.errors.push(safeError(error)))
        .finally(() => {
          this.pending = null;

          if (!this.stopping) {
            this.schedule();
          }
        });
    }, 1000);
  }

  setContext(phase, activeClients) {
    if (this.phase !== phase) {
      // The next sample is a phase baseline. Discard the interval that crossed
      // the boundary so one ramp's CPU cannot leak into another ramp or hold.
      this.previousSnapshot = null;
    }

    this.phase = phase;
    this.activeClients = activeClients;
  }

  sample() {
    const context = { phase: this.phase, active_clients: this.activeClients };
    const next = this.sampleChain
      .catch(() => {})
      .then(() => this.capture(context));
    this.sampleChain = next;

    return next;
  }

  async capture(context) {
    const snapshot = await processResourceSnapshot(
      this.pid,
      this.clockTicksPerSecond,
      this.pageSize,
    );
    const sampledAt = performance.now();
    const previous = this.previousSnapshot;
    const intervalSeconds = previous
      ? (sampledAt - previous.sampledAt) / 1000
      : null;
    const cpuSeconds = previous
      ? snapshot.cpuTimeSeconds - previous.cpuTimeSeconds
      : null;
    const cpuPercent = intervalSeconds > 0 && cpuSeconds >= 0
      ? (cpuSeconds / intervalSeconds) * 100
      : null;

    if (!Number.isFinite(snapshot.cpuTimeSeconds) || !Number.isFinite(snapshot.rssBytes)) {
      throw new Error('Could not parse the Reverb process resource sample.');
    }

    this.previousSnapshot = {
      ...snapshot,
      sampledAt,
    };

    this.samples.push({
      seconds: round((sampledAt - this.startedAt) / 1000),
      ...context,
      cpu_interval_seconds: round(intervalSeconds),
      reverb_cpu_time_seconds: round(snapshot.cpuTimeSeconds),
      reverb_cpu_percent: round(cpuPercent),
      reverb_rss_bytes: Math.round(snapshot.rssBytes),
      client_rss_bytes: process.memoryUsage().rss,
      system_load_1m: round(os.loadavg()[0]),
    });
  }

  async stop() {
    this.stopping = true;

    if (this.timer) {
      clearTimeout(this.timer);
      this.timer = null;
    }

    if (this.pending) {
      await this.pending;
    }
  }
}

async function broadcastTyping(clients, triggerClient) {
  eventSequence += 1;
  const state = eventSequence % 2 === 1 ? 'typing' : 'idle';
  const startedAt = performance.now();
  const waits = clients.map((client) => client.waitForTyping(state, startedAt));
  let triggerHttpMs = null;
  let triggerError = null;

  try {
    triggerHttpMs = await triggerClient.triggerTyping(state === 'typing');
  } catch (error) {
    triggerError = safeError(error);
  }

  const settled = await Promise.allSettled(waits);
  const latencies = settled.flatMap((result) => result.status === 'fulfilled' ? [result.value] : []);

  return {
    expected: clients.length,
    delivered: latencies.length,
    missed: clients.length - latencies.length,
    trigger_http_ms: triggerHttpMs,
    trigger_error: triggerError,
    latencies_ms: latencies,
  };
}

function runtimeEnvironment() {
  const composerLock = JSON.parse(fs.readFileSync(path.join(serverDir, 'composer.lock'), 'utf8'));
  const reverbPackage = [...(composerLock.packages || []), ...(composerLock['packages-dev'] || [])]
    .find((composerPackage) => composerPackage.name === 'laravel/reverb');
  assert(reverbPackage?.version, 'composer.lock contained no laravel/reverb package version.');

  return {
    revision: command('git', ['rev-parse', 'HEAD'], repoRoot),
    working_tree_clean_at_start: workingTreeStatus() === '',
    machine: os.cpus()[0]?.model || 'unknown',
    logical_cpus: os.cpus().length,
    memory_bytes: os.totalmem(),
    platform: os.platform(),
    platform_release: os.release(),
    architecture: os.arch(),
    node: process.version,
    php: command(phpBinary, ['-r', 'echo PHP_VERSION;'], serverDir),
    php_binary: phpBinary,
    laravel: command(phpBinary, ['artisan', '--version'], serverDir),
    reverb: reverbPackage.version,
    http_origin: baseUrl.origin,
    websocket_origin: reverbUrl.origin,
    ...metadata,
  };
}

async function processResourceSnapshot(pid, clockTicksPerSecond, pageSize) {
  if (process.platform === 'linux') {
    const stat = fs.readFileSync(`/proc/${pid}/stat`, 'utf8').trim();
    const commandEnd = stat.lastIndexOf(')');
    assert(commandEnd > 0, 'Could not parse Linux process stat.');
    const fields = stat.slice(commandEnd + 2).split(/\s+/);
    const userTicks = Number(fields[11]);
    const systemTicks = Number(fields[12]);
    const residentPages = Number(fs.readFileSync(`/proc/${pid}/statm`, 'utf8').trim().split(/\s+/)[1]);

    return {
      cpuTimeSeconds: (userTicks + systemTicks) / clockTicksPerSecond,
      rssBytes: residentPages * pageSize,
    };
  }

  const output = await execFilePromise('ps', ['-p', String(pid), '-o', 'time=', '-o', 'rss=']);
  const [cpuTime, rssKb] = output.trim().split(/\s+/);

  return {
    cpuTimeSeconds: parseProcessCpuTime(cpuTime),
    rssBytes: Number(rssKb) * 1024,
  };
}

function parseProcessCpuTime(value) {
  const [dayPart, timePart] = value.includes('-') ? value.split('-', 2) : ['0', value];
  const parts = timePart.split(':').map(Number);
  assert(parts.length >= 2 && parts.length <= 3 && parts.every(Number.isFinite), 'Could not parse process CPU time.');
  const seconds = parts.pop();
  const minutes = parts.pop();
  const hours = parts.pop() || 0;

  return (Number(dayPart) * 24 * 60 * 60) + (hours * 60 * 60) + (minutes * 60) + seconds;
}

function resourceSummary(samples) {
  return {
    samples: samples.length,
    reverb_cpu_percent: distribution(samples.map((sample) => sample.reverb_cpu_percent)),
    reverb_rss_bytes: distribution(samples.map((sample) => sample.reverb_rss_bytes)),
    client_rss_bytes: distribution(samples.map((sample) => sample.client_rss_bytes)),
    system_load_1m: distribution(samples.map((sample) => sample.system_load_1m)),
  };
}

function guardDisposableTarget() {
  assert(process.env.WAYFINDR_CAPACITY_DISPOSABLE === 'YES', 'Set WAYFINDR_CAPACITY_DISPOSABLE=YES only for the disposable measurement desk.');
  assertLoopback(baseUrl, 'HTTP');
  assertLoopback(reverbUrl, 'WebSocket');
  assert(!baseUrl.username && !baseUrl.password, 'HTTP URL must not contain credentials.');
  assert(!reverbUrl.username && !reverbUrl.password, 'WebSocket URL must not contain credentials.');

  if (!allowShortHold) {
    assert(holdSeconds >= 70, 'The default capacity result requires at least a 70-second keepalive hold.');
  }

  assert(
    allowDirty || workingTreeStatus() === '',
    'Capacity harness refuses a dirty worktree; commit or stash changes, or set WAYFINDR_CAPACITY_ALLOW_DIRTY=1 for a development-only run.',
  );
}

function workingTreeStatus() {
  return command('git', ['status', '--porcelain', '--untracked-files=normal'], repoRoot);
}

function verifyReverbProcess(pid) {
  const processCommand = command('ps', ['-p', String(pid), '-o', 'command='], repoRoot);
  assert(processCommand.includes('artisan reverb:start'), 'WAYFINDR_REVERB_PID is not a Laravel Reverb process.');
}

function assertLoopback(url, label) {
  const hostname = url.hostname.replace(/^\[|\]$/g, '');
  assert(['127.0.0.1', '::1', 'localhost'].includes(hostname), `${label} target must be loopback; remote and production targets are refused.`);
}

function extractInputToken(html) {
  const match = html.match(/<input[^>]+name=["']_token["'][^>]+value=["']([^"']+)["']/i)
    || html.match(/<input[^>]+value=["']([^"']+)["'][^>]+name=["']_token["']/i);
  assert(match, 'Login page contained no CSRF token.');

  return match[1];
}

function extractMetaToken(html) {
  const match = html.match(/<meta[^>]+name=["']csrf-token["'][^>]+content=["']([^"']+)["']/i)
    || html.match(/<meta[^>]+content=["']([^"']+)["'][^>]+name=["']csrf-token["']/i);
  assert(match, 'Conversation page contained no CSRF token.');

  return match[1];
}

function parseEnvelopeData(value) {
  return typeof value === 'string' ? JSON.parse(value) : (value || {});
}

function capacitySteps(value) {
  const parsed = value.split(',').map((item) => Number(item.trim()));
  assert(parsed.length > 0 && parsed.every((item) => Number.isInteger(item) && item > 0), 'WAYFINDR_CAPACITY_STEPS must be comma-separated positive integers.');
  const unique = [...new Set(parsed)].sort((left, right) => left - right);
  assert(unique.at(-1) <= 1000, 'Capacity harness refuses more than 1,000 simultaneous sessions in one run.');

  return unique;
}

async function mapLimit(values, limit, worker) {
  const results = new Array(values.length);
  let next = 0;

  async function runWorker() {
    while (next < values.length) {
      const index = next++;
      results[index] = await worker(values[index]);
    }
  }

  await Promise.all(Array.from({ length: Math.min(limit, values.length) }, runWorker));

  return results;
}

function distribution(values) {
  const sorted = values
    .filter((value) => value !== null && value !== undefined && value !== '')
    .map(Number)
    .filter(Number.isFinite)
    .sort((left, right) => left - right);

  if (sorted.length === 0) {
    return { samples: 0, median: null, p95: null, max: null };
  }

  const middle = Math.floor(sorted.length / 2);
  const median = sorted.length % 2 === 0
    ? (sorted[middle - 1] + sorted[middle]) / 2
    : sorted[middle];
  const p95Index = Math.max(0, Math.ceil(sorted.length * 0.95) - 1);

  return {
    samples: sorted.length,
    median: round(median),
    p95: round(sorted[p95Index]),
    max: round(sorted.at(-1)),
  };
}

function countBy(values) {
  return values.reduce((counts, value) => {
    counts[value] = (counts[value] || 0) + 1;

    return counts;
  }, {});
}

function sum(values, read) {
  return values.reduce((total, value) => total + Number(read(value) || 0), 0);
}

function safeError(error) {
  return {
    name: String(error?.name || 'Error').replace(/[^A-Za-z0-9_-]/g, '').slice(0, 40) || 'Error',
    code: String(error?.code || '').replace(/[^A-Za-z0-9_-]/g, '').slice(0, 40) || null,
  };
}

function writeReport(report) {
  const json = `${JSON.stringify(report, null, 2)}\n`;

  if (outputPath) {
    fs.mkdirSync(path.dirname(path.resolve(outputPath)), { recursive: true });
    fs.writeFileSync(outputPath, json);
  }

  process.stdout.write(json);
}

function command(executable, args, cwd) {
  return execFileSync(executable, args, {
    cwd,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
  }).trim();
}

function execFilePromise(executable, args) {
  return new Promise((resolve, reject) => {
    execFile(executable, args, { encoding: 'utf8' }, (error, stdout) => {
      if (error) {
        reject(error);

        return;
      }

      resolve(stdout);
    });
  });
}

function requiredEnv(name) {
  const value = process.env[name];
  assert(value, `Set ${name}.`);

  return value;
}

function requiredUrl(name, protocols) {
  const value = new URL(requiredEnv(name));
  assert(protocols.includes(value.protocol), `${name} must use ${protocols.join(' or ')}.`);

  return value;
}

function positiveIntegerEnv(name, fallback = null) {
  const raw = process.env[name] || fallback;
  const value = Number(raw);
  assert(Number.isInteger(value) && value > 0, `${name} must be a positive integer.`);

  return value;
}

function nonNegativeIntegerEnv(name) {
  const value = Number(requiredEnv(name));
  assert(Number.isInteger(value) && value >= 0, `${name} must be a non-negative integer.`);

  return value;
}

function round(value) {
  if (value === null || value === undefined || value === '') {
    return null;
  }

  const number = Number(value);

  return Number.isFinite(number) ? Math.round(number * 10) / 10 : null;
}

function delay(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function assert(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

guardDisposableTarget();

run().catch((error) => {
  console.error(error.stack || error.message || error);
  process.exit(1);
});

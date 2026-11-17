/**
 * Posición justo después del objeto JSON con que empieza el texto, o -1.
 *
 * Recorre llaves y cadenas en vez de buscar la última llave, porque lo que
 * venga pegado detrás también puede traer llaves.
 */
const jsonObjectEnd = (text) => {
  const start = text.search(/\S/);

  if (start < 0 || '{' !== text[start]) {
    return -1;
  }

  let depth = 0;
  let inString = false;
  let escaped = false;

  for (let i = start; i < text.length; i++) {
    const char = text[i];

    if (inString) {
      if (escaped) {
        escaped = false;
      } else if ('\\' === char) {
        escaped = true;
      } else if ('"' === char) {
        inString = false;
      }
      continue;
    }

    if ('"' === char) {
      inString = true;
    } else if ('{' === char) {
      depth++;
    } else if ('}' === char && 0 === --depth) {
      return i + 1;
    }
  }

  return -1;
};

/**
 * Lee la respuesta JSON de admin-ajax tolerando lo que llegue pegado detrás.
 *
 * En algunos sitios otro plugin, o un aviso de PHP con WP_DEBUG_DISPLAY,
 * escribe algo después de que WordPress ya envió el JSON. El navegador
 * fallaba con «Unexpected non-whitespace character after JSON» aunque la
 * operación hubiera salido bien.
 *
 * Vive fuera de los bloques DOMContentLoaded porque la usan varios de ellos.
 */
const readAjaxJson = async (response) => {
  const text = await response.text();

  try {
    return JSON.parse(text);
  } catch (error) {
    const end = jsonObjectEnd(text);

    if (end < 0) {
      throw error;
    }

    return JSON.parse(text.slice(0, end));
  }
};

document.addEventListener('DOMContentLoaded', () => {
  console.log(
    '%c¿Qué buscas por acá? 👀',
    'font-size:20px;color:#3D5A80;font-weight:bold'
  );
  console.log(
    '%cNexo MCP: conector MCP para WordPress (GPL-2.0-or-later).',
    'font-size:14px;color:#23272F'
  );

  if (typeof nexoMcpAdmin === 'undefined') {
    return;
  }

  const form = document.querySelector('#nexo-mcp-credential-form');
  const dialog = document.querySelector('#nexo-mcp-secret-dialog');
  const message = document.querySelector('.nexo-mcp-form-message');
  const secretField = document.querySelector('#nexo-mcp-secret-value');
  const secretToggle = document.querySelector('#nexo-mcp-toggle-secret');
  const copyStatus = document.querySelector('#nexo-mcp-secret-copy-status');
  const clientInputs = document.querySelectorAll('[name="client"]');
  const labelField = document.querySelector('#nexo-mcp-label');
  const connectButton = document.querySelector('.nexo-mcp-connect-button');
  const secretTitle = document.querySelector('#nexo-mcp-secret-title');
  const selectedClientName = document.querySelector('#nexo-mcp-selected-client');
  const setupPreview = document.querySelector('#nexo-mcp-setup-preview');
  const copySetupButton = document.querySelector('#nexo-mcp-copy-setup');
  const setupCopyStatus = document.querySelector('#nexo-mcp-setup-copy-status');
  const registrationForm = document.querySelector('#nexo-mcp-registration-form');
  const registrationCodeForm = document.querySelector(
    '#nexo-mcp-registration-code-form'
  );
  const registrationMessage = document.querySelector(
    '.nexo-mcp-registration__message'
  );
  let setupInstruction = '';
  let currentCredential = null;
  let selectedClient = 'codex';

  const clientProfiles = {
    codex: {
      name: 'Codex',
      credentialLabel: 'Codex',
      configFile: '~/.codex/config.toml',
      detail:
        'Usa la configuración MCP global del usuario. Codex no carga el servidor si el bloque va sin autenticación.',
      // Codex registra el servidor aunque le falte la credencial, pero luego no
      // lo activa ni explica por qué: aparece configurado y sin herramientas.
      // Por eso el bloque se entrega completo, con la cabecera ya resuelta.
      snippet: (endpoint, header) =>
        [
          '[mcp_servers.nexo_wordpress]',
          `url = "${endpoint}"`,
          `http_headers = { Authorization = "${header}" }`,
        ].join('\n'),
    },
    'claude-code': {
      name: 'Claude Code',
      credentialLabel: 'Claude Code',
      detail:
        'Usa la configuración MCP del usuario, no una configuración guardada dentro del repositorio.',
    },
    vscode: {
      name: 'VS Code con GitHub Copilot',
      credentialLabel: 'VS Code + GitHub Copilot',
      detail:
        'Usa la configuración MCP global de VS Code y su mecanismo de inputs o secretos.',
    },
    cursor: {
      name: 'Cursor',
      credentialLabel: 'Cursor',
      detail:
        'Usa la configuración MCP global del usuario y evita guardar la credencial en .cursor dentro del proyecto.',
    },
    windsurf: {
      name: 'Windsurf',
      credentialLabel: 'Windsurf',
      detail:
        'Usa la configuración MCP global del usuario y mantén la credencial fuera de archivos versionados.',
    },
    'gemini-cli': {
      name: 'Gemini CLI',
      credentialLabel: 'Gemini CLI',
      detail:
        'Usa la configuración MCP global del usuario y una variable de entorno para la autenticación.',
    },
    antigravity: {
      name: 'Antigravity',
      credentialLabel: 'Antigravity',
      detail:
        'Configura el servidor remoto en el perfil global del cliente y mantén el header fuera del proyecto.',
    },
    other: {
      name: 'tu cliente MCP',
      credentialLabel: 'Otro cliente MCP',
      detail:
        'Comprueba que admita transporte HTTP remoto y un header Authorization personalizado.',
    },
  };

  const getClientProfile = (clientId = selectedClient) =>
    clientProfiles[clientId] || clientProfiles.other;

  const detectClientFromCredentialName = (credentialName = '') => {
    const normalizedName = credentialName.toLowerCase();

    return (
      Object.keys(clientProfiles).find((clientId) =>
        normalizedName.includes(
          clientProfiles[clientId].credentialLabel.toLowerCase()
        )
      ) || selectedClient
    );
  };

  const selectClient = (clientId) => {
    selectedClient = clientProfiles[clientId] ? clientId : 'other';
    const profile = getClientProfile();

    if (labelField) {
      labelField.value = profile.credentialLabel;
    }

    if (connectButton) {
      connectButton.textContent = `Generar conexión para ${profile.name}`;
    }

    if (selectedClientName) {
      selectedClientName.textContent = profile.name;
    }
  };

  /**
   * Cabecera Authorization ya resuelta.
   *
   * Se calcula aquí y no se le pide al cliente que la construya. Pedirlo era
   * lo que rompía la conexión con Codex: escribía la configuración sin la
   * credencial y el servidor quedaba registrado pero nunca activo. Y a una
   * persona no se le puede pedir que abra una terminal a codificar en base64
   * para instalar un plugin.
   */
  const authorizationHeader = () => {
    if (!currentCredential) {
      return '';
    }

    const par = `${currentCredential.username}:${currentCredential.password}`;

    // btoa sólo admite Latin-1: un usuario con tilde lo haría reventar.
    const bytes = new TextEncoder().encode(par);
    let binario = '';

    bytes.forEach((b) => {
      binario += String.fromCharCode(b);
    });

    return `Basic ${btoa(binario)}`;
  };

  const buildSetupInstruction = () => {
    if (!currentCredential || !dialog) {
      setupInstruction = '';

      if (setupPreview) {
        setupPreview.value = '';
      }

      return;
    }

    const profile = getClientProfile();
    const endpoint = dialog.dataset.endpoint;
    const header = authorizationHeader();
    const lines = [
      `Configura en ${profile.name} una conexión MCP remota llamada nexo-wordpress.`,
      '',
      'Datos de conexión:',
      '- Nombre del servidor: nexo-wordpress',
      `- Endpoint: ${endpoint}`,
      '- Transporte: HTTP remoto',
      `- Header Authorization: ${header}`,
      '',
      'Requisitos:',
      `- ${profile.detail}`,
      '- Usa el header Authorization tal como está arriba, sin recalcularlo ni modificarlo.',
      '- No guardes la credencial en el repositorio, archivos versionados ni en tu respuesta.',
      '- Esta credencial pertenece sólo a este cliente; no reemplaces otras conexiones MCP existentes.',
      '- Pide mi aprobación antes de ejecutar un comando que cambie configuración fuera del proyecto.',
      '- Si es necesario reiniciar el cliente, configúralo primero y luego indícame exactamente qué reiniciar.',
      '- Después de conectar, prueba únicamente la herramienta get_site_info.',
      '- No crees, edites, publiques ni elimines contenido durante esta prueba.',
      '',
      'Cuando termines, responde sólo con el estado de la conexión y el siguiente paso necesario.',
    ];

    // Para los clientes que se configuran editando un archivo, se entrega el
    // bloque literal. Es la diferencia entre pegar y que funcione, o leer una
    // explicación de cómo escribirlo.
    if (profile.snippet) {
      lines.splice(
        lines.indexOf('Requisitos:'),
        0,
        `Bloque exacto para ${profile.configFile || 'la configuración'}:`,
        '',
        profile.snippet(endpoint, header),
        ''
      );
    }

    setupInstruction = lines.join('\n');

    if (setupPreview) {
      setupPreview.value = setupInstruction.replace(
        currentCredential.password,
        '[CREDENCIAL INCLUIDA AL COPIAR]'
      );
    }
  };

  const request = async (action, fields) => {
    const body = new FormData();
    body.append('action', `nexo_mcp_${action}_credential`);
    body.append('nonce', nexoMcpAdmin.nonce);

    Object.entries(fields).forEach(([key, value]) => body.append(key, value));

    const response = await fetch(nexoMcpAdmin.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body,
    });
    const payload = await readAjaxJson(response);

    if (!response.ok || !payload.success) {
      throw new Error(payload?.data?.message || nexoMcpAdmin.genericError);
    }

    return payload.data;
  };

  const registrationRequest = async (action, fields = {}) => {
    const body = new FormData();
    body.append('action', `nexo_mcp_${action}`);
    body.append('nonce', nexoMcpAdmin.registrationNonce);

    Object.entries(fields).forEach(([key, value]) => body.append(key, value));

    const response = await fetch(nexoMcpAdmin.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body,
    });
    const payload = await readAjaxJson(response);

    if (!response.ok || !payload.success) {
      throw new Error(payload?.data?.message || nexoMcpAdmin.genericError);
    }

    return payload.data;
  };

  const licenseRequest = async (action, fields = {}) => {
    const body = new FormData();
    body.append('action', `nexo_mcp_${action}`);
    body.append('nonce', nexoMcpAdmin.licenseNonce);

    Object.entries(fields).forEach(([key, value]) => body.append(key, value));

    const response = await fetch(nexoMcpAdmin.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body,
    });
    const payload = await readAjaxJson(response);

    if (!response.ok || !payload.success) {
      throw new Error(payload?.data?.message || nexoMcpAdmin.genericError);
    }

    return payload.data;
  };

  const showSecret = (data) => {
    selectedClient = detectClientFromCredentialName(data.name);
    const profile = getClientProfile();

    document.querySelector('#nexo-mcp-secret-message').textContent = data.message;
    document.querySelector('#nexo-mcp-secret-username').textContent = data.username;
    secretTitle.textContent = `Conexión para ${profile.name} creada`;
    selectedClientName.textContent = profile.name;
    secretField.value = data.password;
    secretField.type = 'password';
    secretToggle.setAttribute('aria-pressed', 'false');
    secretToggle.setAttribute('aria-label', nexoMcpAdmin.showSecret);
    copyStatus.textContent = '';
    currentCredential = {
      username: data.username,
      password: data.password,
    };

    if (setupCopyStatus) {
      setupCopyStatus.textContent = '';
    }

    buildSetupInstruction();
    dialog.showModal();
  };

  dialog?.addEventListener('close', () => {
    secretField.value = '';
    setupInstruction = '';
    currentCredential = null;

    if (setupPreview) {
      setupPreview.value = '';
    }

    window.location.reload();
  });

  clientInputs.forEach((input) => {
    input.addEventListener('change', () => {
      selectClient(input.value);

      if (setupCopyStatus) {
        setupCopyStatus.textContent = '';
      }
    });
  });

  selectClient(
    document.querySelector('[name="client"]:checked')?.value || selectedClient
  );

  secretToggle?.addEventListener('click', () => {
    const isVisible = secretField.type === 'text';

    secretField.type = isVisible ? 'password' : 'text';
    secretToggle.setAttribute('aria-pressed', String(!isVisible));
    secretToggle.setAttribute(
      'aria-label',
      isVisible ? nexoMcpAdmin.showSecret : nexoMcpAdmin.hideSecret
    );
    secretField.focus();
  });

  if (form) {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const button = form.querySelector('button[type="submit"]');
      const data = new FormData(form);
      button.disabled = true;
      message.textContent = '';

      try {
        const result = await request('create', {
          user_id: data.get('user_id'),
          label: data.get('label'),
        });
        showSecret(result);
      } catch (error) {
        message.textContent = error.message;
      } finally {
        button.disabled = false;
      }
    });
  }

  const licenseForm = document.querySelector('#nexo-mcp-license-form');

  licenseForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = licenseForm.querySelector('button[type="submit"]');
    const message = licenseForm.parentElement.querySelector(
      '.nexo-mcp-registration__message'
    );
    const key = new FormData(licenseForm).get('license_key');

    button.disabled = true;
    message.textContent = '';

    try {
      const result = await licenseRequest('activate_license', {
        license_key: key,
      });
      message.textContent = result.message;
      // La pantalla que sigue depende de la licencia, así que se recarga en
      // vez de intentar reconstruir el panel entero desde el navegador.
      window.setTimeout(() => window.location.reload(), 900);
    } catch (error) {
      message.textContent = error.message;
      button.disabled = false;
    }
  });

  document.addEventListener('click', async (event) => {
    const licenseButton = event.target.closest('[data-nexo-license-action]');

    if (!licenseButton) {
      return;
    }

    const operation = licenseButton.dataset.nexoLicenseAction;

    if (
      operation === 'release_license' &&
      !window.confirm(nexoMcpAdmin.confirmRelease)
    ) {
      return;
    }

    licenseButton.disabled = true;

    try {
      const result = await licenseRequest(operation);
      window.alert(result.message);

      if (operation === 'release_license') {
        window.location.reload();
      }
    } catch (error) {
      window.alert(error.message);
    } finally {
      licenseButton.disabled = false;
    }
  });

  /**
   * Crea un nodo con clase y texto, sin interpretar HTML.
   */
  const node = (tag, className = '', text) => {
    const element = document.createElement(tag);

    if (className) {
      element.className = className;
    }

    if (undefined !== text) {
      element.textContent = text;
    }

    return element;
  };

  /**
   * Redibuja la tarjeta de Versiones con lo que respondió la plataforma.
   *
   * Replica el marcado de dashboard.php para que el resultado se vea al
   * instante, sin recargar la página.
   */
  const renderUpdates = (card, data) => {
    const t = nexoMcpAdmin.updates;
    const head = card.querySelector('.nexo-mcp-card__head');

    card.querySelector('#nexo-mcp-updates-title').textContent = data.available
      ? t.titleAvailable
      : t.titleCurrent;

    const info = node('div');
    info.append(
      node('strong', '', data.available ? `${data.installed} → ${data.version}` : data.version || data.installed),
      node(
        'small',
        '',
        data.available && data.published
          ? t.published.replace('%s', data.published)
          : t.checked.replace('%s', data.checkedAt)
      )
    );

    const status = node(
      'span',
      `nexo-mcp-status ${data.available ? 'nexo-mcp-status--warning' : 'nexo-mcp-status--online'}`
    );
    const dot = node('span');
    dot.setAttribute('aria-hidden', 'true');
    status.append(dot, document.createTextNode(data.available ? t.statusAvailable : t.statusCurrent));

    const box = node('div', 'nexo-mcp-box');
    box.append(info, status);

    const parts = [box];
    const actions = node('div', 'nexo-mcp-actions');

    if (data.available) {
      if (data.changelog.length) {
        const list = node('ul', 'nexo-mcp-changelog');
        data.changelog.forEach((line) => list.append(node('li', '', line)));
        parts.push(node('p', 'nexo-mcp-eyebrow nexo-mcp-changelog__title', t.changelogTitle), list);
      }

      const link = node('a', 'button button-primary', t.goUpdate);
      link.href = data.updatesUrl;
      actions.append(link);
      parts.push(actions, node('p', 'description', t.availableHint));
    } else {
      const button = node('button', 'button', t.search);
      button.type = 'button';
      button.dataset.nexoCheckUpdates = '';
      actions.append(button);

      const message = node('p', 'nexo-mcp-registration__message nexo-mcp-updates__message', t.noUpdates);
      message.setAttribute('role', 'status');
      message.setAttribute('aria-live', 'polite');

      parts.push(actions, message, node('p', 'description', t.currentHint));
    }

    while (head.nextSibling) {
      head.nextSibling.remove();
    }

    card.append(...parts);
  };

  document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-nexo-check-updates]');

    if (!button) {
      return;
    }

    const card = button.closest('.nexo-mcp-updates');
    const message = card.querySelector('.nexo-mcp-updates__message');
    const label = button.textContent;

    button.disabled = true;
    button.textContent = nexoMcpAdmin.updates.searching;

    if (message) {
      message.textContent = '';
    }

    try {
      renderUpdates(card, await licenseRequest('check_updates'));
    } catch (error) {
      button.disabled = false;
      button.textContent = label;

      if (message) {
        message.textContent = error.message;
      }
    }
  });

  registrationForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = registrationForm.querySelector('button[type="submit"]');
    const data = new FormData(registrationForm);
    const email = data.get('email');
    button.disabled = true;
    registrationMessage.textContent = '';

    try {
      const result = await registrationRequest('request_registration_code', {
        email,
        consent: data.get('consent'),
      });
      registrationMessage.textContent = result.message;
      registrationCodeForm.querySelector('[name="email"]').value = email;
      registrationForm.hidden = true;
      registrationCodeForm.hidden = false;
      registrationCodeForm.querySelector('[name="code"]').focus();
    } catch (error) {
      registrationMessage.textContent = error.message;
    } finally {
      button.disabled = false;
    }
  });

  registrationCodeForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = registrationCodeForm.querySelector('button[type="submit"]');
    const data = new FormData(registrationCodeForm);
    button.disabled = true;
    registrationMessage.textContent = '';

    try {
      const result = await registrationRequest('verify_registration_code', {
        email: data.get('email'),
        code: data.get('code'),
      });
      registrationMessage.textContent = result.message;
      window.location.reload();
    } catch (error) {
      registrationMessage.textContent = error.message;
      button.disabled = false;
    }
  });

  document.addEventListener('click', async (event) => {
    const registrationButton = event.target.closest(
      '[data-nexo-registration-action]'
    );

    if (registrationButton) {
      const operation = registrationButton.dataset.nexoRegistrationAction;

      if (
        operation === 'deactivate' &&
        !window.confirm(nexoMcpAdmin.confirmDisconnect)
      ) {
        return;
      }

      registrationButton.disabled = true;
      registrationMessage.textContent = '';

      try {
        const result = await registrationRequest(`${operation}_registration`);
        registrationMessage.textContent = result.message;

        if (operation === 'deactivate') {
          window.location.reload();
        }
      } catch (error) {
        registrationMessage.textContent = error.message;
      } finally {
        registrationButton.disabled = false;
      }

      return;
    }

    const button = event.target.closest('[data-nexo-credential-action]');

    if (!button) {
      return;
    }

    const operation = button.dataset.nexoCredentialAction;
    const confirmation =
      operation === 'revoke'
        ? nexoMcpAdmin.confirmRevoke
        : nexoMcpAdmin.confirmRotate;

    if (!window.confirm(confirmation)) {
      return;
    }

    button.disabled = true;

    try {
      const result = await request(operation, {
        user_id: button.dataset.userId,
        uuid: button.dataset.uuid,
      });

      if (operation === 'rotate') {
        showSecret(result);
      } else {
        window.location.reload();
      }
    } catch (error) {
      window.alert(error.message);
      button.disabled = false;
    }
  });

  document.querySelector('#nexo-mcp-copy-secret')?.addEventListener('click', async () => {
    const secret = secretField.value;

    try {
      if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(secret);
      } else {
        secretField.select();
        document.execCommand('copy');
        secretField.setSelectionRange(0, 0);
      }

      copyStatus.textContent = nexoMcpAdmin.copySuccess;
    } catch (error) {
      copyStatus.textContent = nexoMcpAdmin.copyError;
    }
  });

  copySetupButton?.addEventListener('click', async () => {
    if (!setupInstruction) {
      return;
    }

    try {
      if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(setupInstruction);
      } else {
        const temporaryField = document.createElement('textarea');
        temporaryField.value = setupInstruction;
        temporaryField.setAttribute('readonly', '');
        temporaryField.style.position = 'fixed';
        temporaryField.style.opacity = '0';
        document.body.appendChild(temporaryField);
        temporaryField.select();
        document.execCommand('copy');
        temporaryField.remove();
      }

      setupCopyStatus.textContent = nexoMcpAdmin.promptCopySuccess;
    } catch (error) {
      setupCopyStatus.textContent = nexoMcpAdmin.promptCopyError;
    }
  });
});

/**
 * Copia al portapapeles el contenido de un bloque de código del panel OAuth.
 */
document.addEventListener('DOMContentLoaded', () => {
  const message = document.getElementById('nexo-mcp-oauth-message');
  let announceTimer = 0;

  // El aviso se ve como un toast flotante: se vacía solo para que no quede
  // tapando el panel.
  const announce = (text) => {
    if (message) {
      message.textContent = text;
      window.clearTimeout(announceTimer);
      announceTimer = window.setTimeout(() => {
        message.textContent = '';
      }, 4000);
    }
  };

  const copyText = async (text) => {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(text);
      return;
    }

    const field = document.createElement('textarea');
    field.value = text;
    field.setAttribute('readonly', '');
    field.style.position = 'fixed';
    field.style.opacity = '0';
    document.body.appendChild(field);
    field.select();
    document.execCommand('copy');
    field.remove();
  };

  /*
   * Pide un documento de descubrimiento y clasifica el desenlace.
   *
   * Se distinguen tres, porque cada uno pide una respuesta distinta: el
   * documento llega y se entiende; el servidor contesta algo que no es nuestro
   * JSON, señal de que atiende /.well-known/ por su cuenta y la petición nunca
   * alcanzó a WordPress; o no hubo respuesta y no se puede concluir nada.
   */
  const fetchDiscovery = async (url) => {
    let response;

    try {
      response = await fetch(url, { credentials: 'omit', cache: 'no-store' });
    } catch (error) {
      return { state: 'unreachable' };
    }

    try {
      return { state: 'ok', document: await response.json() };
    } catch (error) {
      return { state: 'intercepted' };
    }
  };

  const discoveryCard = document.querySelector('.nexo-mcp-discovery');
  const discoveryAlert = document.querySelector('.nexo-mcp-discovery-alert');
  const discoveryStatus = discoveryAlert?.querySelector(
    '.nexo-mcp-discovery-alert__status'
  );

  const paintDiscovery = (tone, label, hint) => {
    const state = discoveryCard?.querySelector('.nexo-mcp-discovery__state');
    const note = discoveryCard?.querySelector('.nexo-mcp-discovery__hint');

    if (!state || !note) {
      return;
    }

    state.className = `nexo-mcp-status nexo-mcp-status--${tone} nexo-mcp-discovery__state`;
    state.innerHTML = '<span aria-hidden="true"></span>';
    state.append(label);
    note.textContent = hint;
  };

  /*
   * Comprueba los dos documentos y deja el panel diciendo la verdad.
   *
   * La comprobación corre en el navegador y no en el servidor a propósito: es
   * el único punto de vista que coincide con el del cliente MCP, que también
   * llega desde fuera. Un servidor que no resuelve su propio dominio daría un
   * fallo inventado.
   */
  const checkDiscovery = async () => {
    if (!discoveryCard) {
      return 'ok';
    }

    const [resource, authorization] = await Promise.all([
      fetchDiscovery(discoveryCard.dataset.resourceMetadata),
      fetchDiscovery(discoveryCard.dataset.authorizationMetadata),
    ]);

    const blocked =
      'intercepted' === resource.state || 'intercepted' === authorization.state;
    const ready = 'ok' === resource.state && 'ok' === authorization.state;

    if (blocked) {
      paintDiscovery(
        'offline',
        nexoMcpAdmin.discoveryBlocked,
        nexoMcpAdmin.discoveryBlockedHint
      );

      if (discoveryAlert) {
        discoveryAlert.hidden = false;
      }

      return 'blocked';
    }

    if (discoveryAlert) {
      discoveryAlert.hidden = true;
    }

    if (ready) {
      paintDiscovery(
        'online',
        nexoMcpAdmin.discoveryReady,
        nexoMcpAdmin.discoveryReadyHint
      );

      return 'ok';
    }

    paintDiscovery(
      'neutral',
      nexoMcpAdmin.discoveryUnknown,
      nexoMcpAdmin.discoveryUnknownHint
    );

    return 'unknown';
  };

  checkDiscovery();

  discoveryAlert
    ?.querySelector('.nexo-mcp-copy-ticket')
    ?.addEventListener('click', async () => {
      const ticket = discoveryAlert.querySelector(
        '.nexo-mcp-discovery-alert__ticket'
      );

      try {
        await copyText(ticket.textContent.trim());

        if (discoveryStatus) {
          discoveryStatus.textContent = nexoMcpAdmin.discoveryTicketCopied;
        }
      } catch (error) {
        if (discoveryStatus) {
          discoveryStatus.textContent = nexoMcpAdmin.copyError;
        }
      }
    });

  discoveryAlert
    ?.querySelector('.nexo-mcp-recheck-discovery')
    ?.addEventListener('click', async (event) => {
      const button = event.currentTarget;

      button.disabled = true;

      if (discoveryStatus) {
        discoveryStatus.textContent = nexoMcpAdmin.discoveryRechecking;
      }

      const result = await checkDiscovery();

      if (discoveryStatus) {
        discoveryStatus.textContent =
          'ok' === result
            ? nexoMcpAdmin.discoveryRepaired
            : nexoMcpAdmin.discoveryStillBlocked;
      }

      button.disabled = false;
    });

  document.querySelectorAll('.nexo-mcp-copy-endpoint').forEach((button) => {
    button.addEventListener('click', async () => {
      const source = document.getElementById(button.dataset.target);

      if (!source) {
        return;
      }

      try {
        await copyText(source.textContent.trim());

        if (button.dataset.target.startsWith('nexo-mcp-prompt-')) {
          announce(nexoMcpAdmin.promptCopySuccess);
        } else if (button.dataset.target === 'nexo-mcp-endpoint-url') {
          announce(nexoMcpAdmin.endpointCopySuccess);
        } else {
          announce(nexoMcpAdmin.commandCopySuccess);
        }
      } catch (error) {
        announce(nexoMcpAdmin.copyError);
      }
    });
  });

  document.querySelectorAll('.nexo-mcp-copy-codex-url').forEach((button) => {
    button.addEventListener('click', async () => {
      const originalLabel = button.textContent;

      try {
        await copyText(button.dataset.url || '');
        button.textContent = 'URL copiada';
        announce(nexoMcpAdmin.codexUrlCopySuccess);

        window.setTimeout(() => {
          button.textContent = originalLabel;
        }, 2200);
      } catch (error) {
        announce(nexoMcpAdmin.copyError);
      }
    });
  });

  document.querySelectorAll('.nexo-mcp-test-oauth').forEach((button) => {
    button.addEventListener('click', async () => {
      const diagnostic = button.closest('.nexo-mcp-oauth-diagnostic');
      const status = diagnostic?.querySelector(
        '.nexo-mcp-oauth-diagnostic__status'
      );

      if (!diagnostic || !status) {
        return;
      }

      button.disabled = true;
      diagnostic.dataset.state = 'running';
      status.textContent = nexoMcpAdmin.oauthTestRunning;

      try {
        const challengeResponse = await fetch(diagnostic.dataset.endpoint, {
          method: 'POST',
          credentials: 'omit',
          cache: 'no-store',
          headers: {
            Accept: 'application/json, text/event-stream',
            'Content-Type': 'application/json',
            'MCP-Protocol-Version': '2025-06-18',
          },
          body: JSON.stringify({
            jsonrpc: '2.0',
            id: 1,
            method: 'initialize',
            params: {
              protocolVersion: '2025-06-18',
              capabilities: {},
              clientInfo: { name: 'nexo-oauth-diagnostic', version: '1.0' },
            },
          }),
        });
        const challenge =
          challengeResponse.headers.get('WWW-Authenticate') || '';

        if (
          challengeResponse.status !== 401 ||
          !challenge.toLowerCase().includes('resource_metadata=')
        ) {
          throw new Error('El endpoint no publicó el desafío OAuth esperado.');
        }

        const [resourceResult, authorizationResult] = await Promise.all([
          fetchDiscovery(diagnostic.dataset.resourceMetadata),
          fetchDiscovery(diagnostic.dataset.authorizationMetadata),
        ]);

        // Un servidor que atiende /.well-known/ por su cuenta no es un fallo de
        // configuración del conector, así que se nombra aparte y se despliega
        // el aviso del inicio, donde está el texto para el hosting.
        if (
          'intercepted' === resourceResult.state ||
          'intercepted' === authorizationResult.state
        ) {
          checkDiscovery();

          throw new Error(nexoMcpAdmin.discoveryBlockedHint);
        }

        if ('ok' !== resourceResult.state || 'ok' !== authorizationResult.state) {
          throw new Error(nexoMcpAdmin.discoveryUnknownHint);
        }

        const resource = resourceResult.document;
        const authorization = authorizationResult.document;
        const expectedResource = diagnostic.dataset.endpoint.replace(/\/$/, '');

        if (
          String(resource.resource || '').replace(/\/$/, '') !==
            expectedResource ||
          !Array.isArray(resource.authorization_servers) ||
          !resource.authorization_servers.includes(authorization.issuer) ||
          !authorization.authorization_endpoint ||
          !authorization.token_endpoint ||
          !authorization.registration_endpoint ||
          !authorization.code_challenge_methods_supported?.includes('S256')
        ) {
          throw new Error('Los documentos OAuth están incompletos o no coinciden.');
        }

        diagnostic.dataset.state = 'success';
        status.textContent = nexoMcpAdmin.oauthTestSuccess;
      } catch (error) {
        diagnostic.dataset.state = 'error';
        status.textContent = `${nexoMcpAdmin.oauthTestError} ${error.message}`;
      } finally {
        button.disabled = false;
      }
    });
  });

  document.querySelectorAll('.nexo-mcp-copy-instruction').forEach((button) => {
    button.addEventListener('click', async () => {
      const recipe = button.closest('.nexo-mcp-oauth-recipe');
      const command = recipe?.querySelector('code[id^="nexo-mcp-oauth-code-"]');
      const prompt = recipe?.querySelector('.nexo-mcp-hidden-prompt');

      if (!recipe || !command || !prompt) {
        announce(nexoMcpAdmin.copyError);
        return;
      }

      const clientName = recipe.dataset.clientName || 'mi cliente de IA';
      const serverName = recipe.dataset.serverName || 'nexo-wordpress';
      const profile = document.querySelector(
        'input[name="nexo_mcp_connection_profile"]:checked'
      )?.value;
      const profileName = profile === 'read' ? 'solo lectura' : 'administración';
      const bridgeNotice = recipe.dataset.bridge === 'true'
        ? [
            '',
            'Ojo: este comando usa mcp-remote@0.1.38 como puente externo temporal porque el transporte HTTP nativo de Codex 0.146 falla después de notifications/initialized.',
            'Explícame ese uso y pide mi aprobación antes de descargarlo o ejecutarlo.',
          ]
        : [];
      const instruction = [
        'Quiero conectar este WordPress contigo mediante Nexo MCP.',
        '',
        `Configura una conexión MCP llamada ${serverName} para ${clientName}.`,
        'Usa exactamente esta configuración o comando:',
        '',
        command.textContent.trim(),
        ...bridgeNotice,
        '',
        'Reglas de seguridad:',
        '- Pide mi aprobación antes de ejecutar comandos que cambien configuración fuera del proyecto.',
        '- No guardes credenciales ni tokens en el repositorio ni los muestres en tu respuesta.',
        `- El perfil OAuth activo es ${profileName}.`,
        '- No reemplaces ni elimines otras conexiones MCP.',
        '- Si este servidor ya existe, actualízalo sin duplicarlo.',
		'',
        'Conexión y prueba:',
        '- Abre el flujo OAuth de WordPress y espera que yo autorice en el navegador.',
        '- Cuando quede conectado, ejecuta únicamente nexo-mcp-get-site-info.',
        '- No modifiques el sitio durante esta prueba.',
        '',
        prompt.textContent.trim(),
        '',
        'Al terminar, dime sólo si quedó conectado, el nombre del sitio y su URL.',
      ]
        .filter((line, index, lines) => line || lines[index - 1])
        .join('\n');
      const originalLabel = button.textContent;

      try {
        await copyText(instruction);
        button.textContent = 'Instrucción copiada';
        announce(nexoMcpAdmin.promptCopySuccess);

        window.setTimeout(() => {
          button.textContent = originalLabel;
        }, 2200);
      } catch (error) {
        announce(nexoMcpAdmin.copyError);
      }
    });
  });

  document.querySelectorAll('.nexo-mcp-revoke-oauth').forEach((button) => {
    button.addEventListener('click', async () => {
      if (!window.confirm(nexoMcpAdmin.confirmRevokeOauth)) {
        return;
      }

      button.disabled = true;

      const payload = new URLSearchParams({
        action: 'nexo_mcp_revoke_oauth_connection',
        nonce: nexoMcpAdmin.oauthNonce,
        client_id: button.dataset.client,
        user_id: button.dataset.user,
      });

      try {
        const response = await fetch(nexoMcpAdmin.ajaxUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: payload,
        });
        const result = await readAjaxJson(response);

        if (!result.success) {
          announce(result.data?.message || nexoMcpAdmin.genericError);
          button.disabled = false;
          return;
        }

        announce(result.data.message);
        button.closest('.nexo-mcp-credential-card')?.remove();
      } catch (error) {
        announce(nexoMcpAdmin.genericError);
        button.disabled = false;
      }
    });
  });
});

/**
 * Alterna las instrucciones de conexión OAuth según el cliente elegido.
 */
document.addEventListener('DOMContentLoaded', () => {
  /*
   * Opciones sensibles. Moverlas hacia el lado riesgoso pide escribir una
   * palabra; volver a lo seguro es directo. Es una protección contra el clic
   * distraído, no un control de acceso: guardar sigue exigiendo nonce y
   * capacidad en el servidor. Los textos llegan escapados en atributos data-*
   * y se escriben con textContent, nunca como HTML.
   */
  const confirmDialog = document.querySelector('#nexo-mcp-confirm');
  const confirmInput = confirmDialog?.querySelector('.nexo-mcp-confirm__input');
  const confirmAccept = confirmDialog?.querySelector('.nexo-mcp-confirm__accept');
  let confirmWord = '';
  let settleConfirm = null;

  const isRisky = (field) =>
    field.dataset.nexoRisk === 'on' ? field.checked : !field.checked;

  // Se recortan los espacios para admitir lo pegado con uno de más.
  const wordMatches = () => confirmInput.value.trim() === confirmWord;

  // Resuelve true cuando se escribió la palabra y false si se canceló.
  const askConfirmation = (field) => {
    if (!confirmDialog) {
      return Promise.resolve(true);
    }

    const label = confirmDialog.querySelector('.nexo-mcp-confirm__label');
    const [before, after = ''] = (confirmDialog.dataset.label || '%s').split('%s');
    const word = document.createElement('strong');

    confirmWord = field.dataset.nexoConfirmWord || '';
    word.textContent = confirmWord;
    label.replaceChildren(before, word, after);
    confirmDialog.querySelector('.nexo-mcp-confirm__title').textContent =
      field.dataset.nexoConfirmTitle || '';
    confirmDialog.querySelector('.nexo-mcp-confirm__text').textContent =
      field.dataset.nexoConfirmText || '';
    confirmInput.value = '';
    confirmAccept.disabled = true;

    return new Promise((resolve) => {
      settleConfirm = (accepted) => {
        settleConfirm = null;

        if (confirmDialog.open) {
          confirmDialog.close();
        }

        field.focus();
        resolve(accepted);
      };

      confirmDialog.showModal();
      confirmInput.focus();
    });
  };

  if (confirmDialog) {
    confirmInput.addEventListener('input', () => {
      confirmAccept.disabled = !wordMatches();
    });

    confirmDialog.querySelector('form').addEventListener('submit', (event) => {
      event.preventDefault();

      if (wordMatches()) {
        settleConfirm?.(true);
      }
    });

    confirmDialog
      .querySelector('.nexo-mcp-confirm__cancel')
      .addEventListener('click', () => settleConfirm?.(false));

    // Esc dispara «cancel»; «close» cubre cualquier otro cierre.
    confirmDialog.addEventListener('cancel', (event) => {
      event.preventDefault();
      settleConfirm?.(false);
    });
    confirmDialog.addEventListener('close', () => settleConfirm?.(false));
  }

  // Cancelar devuelve el interruptor a su estado anterior.
  document
    .querySelectorAll('.nexo-mcp-toggle input[data-nexo-risk]')
    .forEach((field) => {
      field.addEventListener('change', async () => {
        if (isRisky(field) && !(await askConfirmation(field))) {
          field.checked = !field.checked;
        }
      });
    });

  const options = document.querySelectorAll('input[name="nexo_mcp_oauth_client"]');
  const recipes = document.querySelectorAll('.nexo-mcp-oauth-recipe');
  const profileOptions = document.querySelectorAll(
    'input[name="nexo_mcp_connection_profile"]'
  );
  const profileGrid = document.querySelector('.nexo-mcp-profile-grid');
  const profileStatus = document.querySelector('#nexo-mcp-profile-status');
  const codexBridgeCodes = document.querySelectorAll('[data-codex-bridge-endpoint]');
  const codexNativeCode = document.querySelector('[data-codex-native-endpoint]');
  const readOnlySetting = document.querySelector(
    'input[name="nexo_mcp_settings[read_only]"]'
  );

  if (!options.length || !recipes.length) {
    return;
  }

  const show = (key) => {
    recipes.forEach((recipe) => {
      recipe.hidden = recipe.dataset.client !== key;
    });
  };

  // El cliente elegido se recuerda en el navegador. El marcado que llega desde
  // PHP es siempre el primero de la lista, así que sin esto el panel afirmaba
  // que la conexión era con Claude Code aunque el usuario hubiera elegido otro.
  const clienteRecordado = 'nexo-mcp-oauth-client';

  const recordarCliente = (key) => {
    try {
      window.localStorage.setItem(clienteRecordado, key);
    } catch (error) {
      // Navegación privada o almacenamiento lleno: se pierde la preferencia y
      // nada más, la conexión no depende de esto.
    }
  };

  options.forEach((option) => {
    option.addEventListener('change', () => {
      if (option.checked) {
        show(option.value);
        recordarCliente(option.value);
      }
    });
  });

  try {
    const guardado = window.localStorage.getItem(clienteRecordado);
    const elegido = guardado
      ? Array.from(options).find((option) => option.value === guardado)
      : null;

    if (elegido) {
      elegido.checked = true;
      show(elegido.value);
    }
  } catch (error) {
    // Sin almacenamiento disponible se queda el que marcó PHP.
  }

  const scopesFor = (profile) =>
    profile === 'read'
      ? profileGrid?.dataset.readScopes || 'mcp:connect mcp:read'
      : profileGrid?.dataset.manageScopes || 'mcp:connect mcp:read mcp:write';

  const updateCodexCommand = (scopes) => {
	const requestedScopes = scopes.trim().split(/\s+/).join(',');
	const npxExecutable = /Windows/i.test(window.navigator.userAgent)
	  ? 'npx.cmd'
	  : 'npx';

	codexBridgeCodes.forEach((code) => {
	  const metadata = JSON.stringify({ scope: scopes });

	  code.textContent = [
		`codex mcp add nexo_wordpress -- ${npxExecutable} -y mcp-remote@0.1.38`,
		code.dataset.codexBridgeEndpoint,
		'--transport http-only',
		`--static-oauth-client-metadata '${metadata}'`,
	  ].join(' ');
	});

	if (codexNativeCode) {
	  const endpoint = codexNativeCode.dataset.codexNativeEndpoint;

	  codexNativeCode.textContent = [
		`codex mcp add nexo_wordpress --url ${endpoint} --oauth-resource ${endpoint}`,
		`codex mcp login nexo_wordpress --scopes ${requestedScopes}`,
	  ].join('\n');
	}
  };

  const activeProfile = Array.from(profileOptions).find((option) => option.checked);
  updateCodexCommand(scopesFor(activeProfile?.value || 'read'));

  profileOptions.forEach((option) => {
    option.addEventListener('change', async () => {
      if (!option.checked) {
        return;
      }

      const previous = Array.from(profileOptions).find(
        (candidate) => candidate !== option && !candidate.checked
      );

      // «Revisar y editar» apaga el modo sólo lectura: se confirma antes de
      // enviar nada y, si se cancela, el perfil vuelve al anterior.
      if (option.dataset.nexoRisk && !(await askConfirmation(option))) {
        option.checked = false;

        if (previous) {
          previous.checked = true;
        }

        return;
      }

      profileOptions.forEach((candidate) => {
        candidate.disabled = true;
      });

      if (profileStatus) {
        profileStatus.textContent = 'Guardando perfil de acceso…';
      }

      const payload = new URLSearchParams({
        action: 'nexo_mcp_set_connection_profile',
        nonce: nexoMcpAdmin.oauthNonce,
        profile: option.value,
      });

      try {
        const response = await fetch(nexoMcpAdmin.ajaxUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: payload,
        });
        const result = await readAjaxJson(response);

        if (!response.ok || !result.success) {
          throw new Error(result.data?.message || nexoMcpAdmin.genericError);
        }

        updateCodexCommand(result.data.scopes || scopesFor(option.value));

        if (readOnlySetting) {
          readOnlySetting.checked = option.value === 'read';
        }

        if (profileStatus) {
          profileStatus.textContent = result.data.message;
        }
      } catch (error) {
        option.checked = false;

        if (previous) {
          previous.checked = true;
          updateCodexCommand(scopesFor(previous.value));

          if (readOnlySetting) {
            readOnlySetting.checked = previous.value === 'read';
          }
        }

        if (profileStatus) {
          profileStatus.textContent = error.message;
        }
      } finally {
        profileOptions.forEach((candidate) => {
          candidate.disabled = false;
        });
      }
    });
  });
});

/**
 * Pestañas del panel.
 *
 * PHP ya entrega visible la pestaña correcta; aquí sólo se cambia de una a
 * otra sin recargar y se deja la URL al día, para que una recarga (tras
 * revocar, cerrar el diálogo o liberar la licencia) vuelva al mismo sitio.
 * Sin JavaScript los enlaces siguen funcionando como navegación normal.
 */
document.addEventListener('DOMContentLoaded', () => {
  const panels = Array.from(document.querySelectorAll('[data-nexo-panel]'));
  const tabs = document.querySelectorAll('.nexo-mcp-tab[data-nexo-tab]');
  const nav = document.querySelector('.nexo-mcp-tabs');

  if (!panels.length) {
    return;
  }

  const activate = (id) => {
    const target = panels.find((panel) => panel.dataset.nexoPanel === id);

    if (!target) {
      return false;
    }

    panels.forEach((panel) => {
      panel.hidden = panel !== target;
    });

    tabs.forEach((tab) => {
      if (tab.dataset.nexoTab === id) {
        tab.setAttribute('aria-current', 'page');
      } else {
        tab.removeAttribute('aria-current');
      }
    });

    return true;
  };

  document.querySelectorAll('[data-nexo-tab]').forEach((link) => {
    link.addEventListener('click', (event) => {
      // Ctrl/Cmd + clic sigue abriendo la pestaña en otra ventana.
      if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey) {
        return;
      }

      const id = link.dataset.nexoTab;

      if (!activate(id)) {
        return;
      }

      event.preventDefault();

      const url = new URL(window.location.href);
      url.searchParams.set('tab', id);
      url.searchParams.delete('settings-updated');
      url.hash = '';
      window.history.replaceState(null, '', url);

      // Un enlace dentro del contenido (p. ej. «Empezar») deja la vista
      // a media página; se sube hasta las pestañas.
      if (!link.classList.contains('nexo-mcp-tab')) {
        nav?.scrollIntoView({ block: 'nearest' });
      }
    });
  });

  // Un ancla a algo que vive en otra pestaña (#nexo-mcp-reportar) la abre.
  if (window.location.hash.length > 1) {
    let anchored = null;

    try {
      anchored = document.getElementById(
        decodeURIComponent(window.location.hash.slice(1))
      );
    } catch (error) {
      // Ancla mal codificada: se ignora.
    }

    const panel = anchored?.closest('[data-nexo-panel]');

    if (panel && activate(panel.dataset.nexoPanel)) {
      anchored.scrollIntoView({ block: 'start' });
    }
  }
});

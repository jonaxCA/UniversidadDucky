<?php
/**
 * Chatbot widget — burbuja flotante. Inclúyelo justo antes de </body>.
 * Requiere: usuario logueado (currentUser disponible).
 */
$cbMe = currentUser();
?>
<!-- ════════════════ CHATBOT DUCKY ════════════════ -->
<div id="duckyBot">

    <!-- Burbuja flotante -->
    <button id="ducky-launcher" type="button" aria-label="Abrir asistente Ducky">
        <i class="fa-solid fa-comments"></i>
        <span class="ducky-badge" id="duckyBadge" hidden>1</span>
    </button>

    <!-- Panel del chat -->
    <div id="ducky-panel" hidden>

        <header class="ducky-header">
            <div class="ducky-brand">
                <div class="ducky-avatar">
                    <i class="fa-solid fa-feather-pointed"></i>
                </div>
                <div>
                    <div class="ducky-title">Ducky <span class="ducky-status">● online</span></div>
                    <div class="ducky-sub">Asistente de biblioteca</div>
                </div>
            </div>
            <button id="ducky-close" type="button" aria-label="Cerrar">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </header>

        <div class="ducky-messages" id="duckyMessages">
            <div class="ducky-msg bot">
<div class="ducky-bubble">¡Hola, <?= htmlspecialchars(explode(' ', $cbMe['nombre'])[0] ?? 'lector') ?>! 👋
Soy Ducky. Puedo ayudarte a buscar libros, ver disponibilidad, consultar tus multas y préstamos, o explicarte los horarios.</div>
            </div>
            <div class="ducky-suggestions" id="duckySuggestions">
                <button class="ducky-chip" data-q="¿Tienen libros de García Márquez?">📖 Libros de García Márquez</button>
                <button class="ducky-chip" data-q="¿Tengo multas pendientes?">💰 ¿Tengo multas?</button>
                <button class="ducky-chip" data-q="¿Cuáles son los horarios?">🕐 Horarios</button>
                <button class="ducky-chip" data-q="¿Cuántos días puedo llevarme un libro?">📚 Reglas de préstamo</button>
            </div>
        </div>

        <form class="ducky-input-bar" id="duckyForm" autocomplete="off">
            <input type="text" id="duckyInput"
                   placeholder="Escribe tu pregunta..."
                   maxlength="500"
                   aria-label="Mensaje al asistente">
            <button type="submit" id="duckySend" aria-label="Enviar">
                <i class="fa-solid fa-paper-plane"></i>
            </button>
        </form>

        <div class="ducky-footer">
            Las respuestas pueden contener errores. Verifica con un bibliotecario.
        </div>
    </div>
</div>

<style>
/* ─────────── Burbuja flotante ─────────── */
#ducky-launcher {
    position: fixed; bottom: 22px; right: 22px;
    width: 58px; height: 58px;
    border: none; border-radius: 50%;
    background: linear-gradient(135deg, #0f3524 0%, #1a5c3a 100%);
    color: #fff; font-size: 22px;
    box-shadow: 0 8px 24px rgba(15,53,36,.4);
    cursor: pointer; z-index: 9998;
    transition: transform .2s, box-shadow .2s;
    display: flex; align-items: center; justify-content: center;
}
#ducky-launcher:hover { transform: scale(1.08); box-shadow: 0 12px 32px rgba(15,53,36,.5); }
#ducky-launcher.open  { background: #94a3b8; }

.ducky-badge {
    position: absolute; top: -2px; right: -2px;
    background: #dc2626; color: #fff;
    width: 20px; height: 20px; border-radius: 50%;
    font-size: 11px; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
    border: 2px solid #fff;
}

/* ─────────── Panel del chat ─────────── */
#ducky-panel {
    position: fixed; bottom: 92px; right: 22px;
    width: 380px; height: 560px; max-height: calc(100vh - 120px);
    background: #fff; border-radius: 16px;
    box-shadow: 0 16px 48px rgba(0,0,0,.18);
    display: flex; flex-direction: column;
    z-index: 9999; overflow: hidden;
    font-family: 'Inter', sans-serif;
    animation: duckySlide .25s ease-out;
}
/* Respetar el atributo hidden — si no, gana display:flex de arriba */
#ducky-panel[hidden] { display: none; }
@keyframes duckySlide {
    from { opacity: 0; transform: translateY(20px) scale(.96); }
    to   { opacity: 1; transform: translateY(0)    scale(1); }
}

/* Header */
.ducky-header {
    background: linear-gradient(135deg, #0f3524 0%, #1a5c3a 100%);
    color: #fff; padding: 14px 16px;
    display: flex; align-items: center; justify-content: space-between;
}
.ducky-brand { display: flex; align-items: center; gap: 12px; }
.ducky-avatar {
    width: 40px; height: 40px; border-radius: 50%;
    background: rgba(255,255,255,.18);
    display: flex; align-items: center; justify-content: center;
    font-size: 18px;
}
.ducky-title  { font-size: 15px; font-weight: 700; line-height: 1; display: flex; align-items: center; gap: 8px; }
.ducky-status { font-size: 10px; color: #86efac; font-weight: 500; }
.ducky-sub    { font-size: 11px; opacity: .8; margin-top: 3px; }

#ducky-close {
    background: none; border: none; color: #fff;
    font-size: 18px; cursor: pointer; opacity: .7;
    padding: 4px 8px; border-radius: 6px;
}
#ducky-close:hover { opacity: 1; background: rgba(255,255,255,.1); }

/* Mensajes */
.ducky-messages {
    flex: 1; overflow-y: auto;
    padding: 16px; background: #f8fafc;
    display: flex; flex-direction: column; gap: 10px;
}
.ducky-msg { display: flex; }
.ducky-msg.user { justify-content: flex-end; }
.ducky-msg.bot  { justify-content: flex-start; }

.ducky-bubble {
    max-width: 85%; padding: 10px 14px;
    border-radius: 14px; font-size: 14px; line-height: 1.45;
    word-wrap: break-word; white-space: pre-wrap;
}
.ducky-msg.bot .ducky-bubble {
    background: #fff; color: #1e293b;
    border: 1px solid #e2e8f0;
    border-bottom-left-radius: 4px;
}
.ducky-msg.user .ducky-bubble {
    background: #0f3524; color: #fff;
    border-bottom-right-radius: 4px;
}
.ducky-msg.error .ducky-bubble {
    background: #fef2f2; color: #b91c1c;
    border: 1px solid #fecaca;
}

/* Typing indicator */
.ducky-typing { display: flex; gap: 4px; padding: 12px 16px; }
.ducky-typing span {
    width: 7px; height: 7px; border-radius: 50%;
    background: #94a3b8;
    animation: duckyTyping 1.4s infinite ease-in-out;
}
.ducky-typing span:nth-child(2) { animation-delay: .2s; }
.ducky-typing span:nth-child(3) { animation-delay: .4s; }
@keyframes duckyTyping {
    0%, 80%, 100% { opacity: .3; transform: scale(.85); }
    40%           { opacity: 1;  transform: scale(1.1);  }
}

/* Sugerencias */
.ducky-suggestions {
    display: flex; flex-wrap: wrap; gap: 6px;
    margin-top: 4px; padding: 0 4px;
}
.ducky-chip {
    background: #fff; color: #0f3524;
    border: 1px solid #bbf7d0; border-radius: 16px;
    padding: 6px 12px; font-size: 12px; font-weight: 500;
    cursor: pointer; transition: .15s;
    font-family: inherit;
}
.ducky-chip:hover { background: #f0fdf4; transform: translateY(-1px); }

/* Input */
.ducky-input-bar {
    display: flex; gap: 8px; padding: 12px;
    background: #fff; border-top: 1px solid #e2e8f0;
}
#duckyInput {
    flex: 1; padding: 10px 14px;
    border: 1.5px solid #e2e8f0; border-radius: 22px;
    font-size: 14px; font-family: inherit;
    outline: none; transition: border-color .2s;
}
#duckyInput:focus { border-color: #0f3524; }
#duckySend {
    width: 40px; height: 40px;
    background: #0f3524; color: #fff;
    border: none; border-radius: 50%;
    cursor: pointer; transition: background .2s;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
#duckySend:hover:not(:disabled) { background: #1a5c3a; }
#duckySend:disabled { background: #94a3b8; cursor: not-allowed; }

.ducky-footer {
    text-align: center;
    font-size: 10px; color: #94a3b8;
    padding: 6px 12px 8px;
    background: #fff;
    border-top: 1px solid #f1f5f9;
}

/* ─────────── Mobile: panel full-screen ─────────── */
@media (max-width: 600px) {
    #ducky-launcher { bottom: 16px; right: 16px; width: 52px; height: 52px; font-size: 20px; }
    #ducky-panel {
        bottom: 0; right: 0; left: 0; top: 0;
        width: 100%; height: 100%; max-height: 100%;
        border-radius: 0;
    }
    #duckyInput { font-size: 16px; } /* anti-zoom iOS */
}
</style>

<script>
(function() {
    const launcher  = document.getElementById('ducky-launcher');
    const panel     = document.getElementById('ducky-panel');
    const closeBtn  = document.getElementById('ducky-close');
    const messages  = document.getElementById('duckyMessages');
    const form      = document.getElementById('duckyForm');
    const input     = document.getElementById('duckyInput');
    const sendBtn   = document.getElementById('duckySend');
    const suggBox   = document.getElementById('duckySuggestions');

    // Abrir / cerrar
    launcher.addEventListener('click', () => {
        const open = !panel.hidden;
        panel.hidden = open;
        launcher.classList.toggle('open', !open);
        if (!open) setTimeout(() => input.focus(), 100);
    });
    closeBtn.addEventListener('click', () => {
        panel.hidden = true;
        launcher.classList.remove('open');
    });

    // Sugerencias
    suggBox.addEventListener('click', (e) => {
        const chip = e.target.closest('.ducky-chip');
        if (!chip) return;
        sendMessage(chip.dataset.q);
        suggBox.remove();
    });

    // Submit
    form.addEventListener('submit', (e) => {
        e.preventDefault();
        const txt = input.value.trim();
        if (!txt) return;
        sendMessage(txt);
        input.value = '';
    });

    // Enviar mensaje
    async function sendMessage(text) {
        addMessage('user', text);
        const typing = addTyping();
        sendBtn.disabled = true;

        try {
            const resp = await fetch('chatbot.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ message: text })
            });
            const data = await resp.json();
            typing.remove();

            if (data.error) {
                addMessage('error', data.error);
            } else if (data.offline) {
                addMessage('error', data.response);
            } else {
                addMessage('bot', data.response || 'Sin respuesta.');
            }
        } catch (err) {
            typing.remove();
            addMessage('error', 'No pude contactar al servidor. Revisa tu conexión.');
        } finally {
            sendBtn.disabled = false;
            input.focus();
        }
    }

    function addMessage(role, text) {
        const div    = document.createElement('div');
        div.className = `ducky-msg ${role}`;
        const bubble = document.createElement('div');
        bubble.className = 'ducky-bubble';
        bubble.textContent = text;
        div.appendChild(bubble);
        messages.appendChild(div);
        messages.scrollTop = messages.scrollHeight;
        return div;
    }

    function addTyping() {
        const div    = document.createElement('div');
        div.className = 'ducky-msg bot';
        const bubble = document.createElement('div');
        bubble.className = 'ducky-bubble';
        bubble.style.padding = '0';
        bubble.innerHTML = '<div class="ducky-typing"><span></span><span></span><span></span></div>';
        div.appendChild(bubble);
        messages.appendChild(div);
        messages.scrollTop = messages.scrollHeight;
        return div;
    }
})();
</script>

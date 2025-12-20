/* === CSS MATRIX3D SOLVER (Для точної імітації FFmpeg Perspective) === */
function svbSolveHomography(src, dst) {
    let t = 0;
    let i = 0;
    let x = 0;
    let y = 0;
    let u = 0;
    let v = 0;
    let a = [];
    let b = [];
    let A = [];
    let B = [];
    let X = [];

    for (i = 0; i < 4; i++) {
        x = src[i][0];
        y = src[i][1];
        u = dst[i][0];
        v = dst[i][1];
        a.push([x, y, 1, 0, 0, 0, -x * u, -y * u]);
        a.push([0, 0, 0, x, y, 1, -x * v, -y * v]);
        b.push(u);
        b.push(v);
    }

    // Gaussian elimination
    const n = 8;
    for (i = 0; i < n; i++) {
        let maxEl = Math.abs(a[i][i]);
        let maxRow = i;
        for (let k = i + 1; k < n; k++) {
            if (Math.abs(a[k][i]) > maxEl) {
                maxEl = Math.abs(a[k][i]);
                maxRow = k;
            }
        }

        for (let k = i; k < n; k++) {
            let tmp = a[maxRow][k];
            a[maxRow][k] = a[i][k];
            a[i][k] = tmp;
        }
        let tmp = b[maxRow];
        b[maxRow] = b[i];
        b[i] = tmp;

        for (let k = i + 1; k < n; k++) {
            let c = -a[k][i] / a[i][i];
            for (let j = i; j < n; j++) {
                if (i === j) {
                    a[k][j] = 0;
                } else {
                    a[k][j] += c * a[i][j];
                }
            }
            b[k] += c * b[i];
        }
    }

    X = new Array(n);
    for (i = n - 1; i > -1; i--) {
        X[i] = b[i] / a[i][i];
        for (let k = i - 1; k > -1; k--) {
            b[k] -= a[k][i] * X[i];
        }
    }
    
    // Формуємо matrix3d(a, b, 0, c, d, e, 0, f, 0, 0, 1, 0, g, h, 0, 1)
    // CSS Matrix:
    // 0:h0  1:h3  2:0   3:h6
    // 4:h1  5:h4  6:0   7:h7
    // 8:0   9:0   10:1  11:0
    // 12:h2 13:h5 14:0  15:1  <-- h8=1 implicitly in solution but X[8] is 1
    
    return [
        X[0], X[3], 0, X[6],
        X[1], X[4], 0, X[7],
        0,    0,    1, 0,
        X[2], X[5], 0, 1
    ];
}



function svbArrToCssMatrix(m) {
    return 'matrix3d(' + m.map(v => v.toFixed(6)).join(',') + ')';
}

// Глобальні змінні стану
let svbCurrentChildCount = 1;
const SVB_STATE_KEY = 'svb_state_v1';
let svbState = null;
let svbRecoveredFromLookup = false;
let svbLookupDownloadUrl = '';
let svbLookupInFlight = false;
let svbStep2InFlight = false;
const SVB_DEBUG = !!(window.SVB_DATA && window.SVB_DATA.debug && window.SVB_DATA.debug.enabled);

function svbDebugLog(tag, payload) {
  if (!SVB_DEBUG) return;
  try {
    console.log(`[SVB DEBUG] ${tag}`,(payload ?? {}));
  } catch (e) {}
}

function svbGetVideoSelectionMap() {
    const state = svbLoadState();
    if (!state.video_selection_per_count || typeof state.video_selection_per_count !== 'object') {
        state.video_selection_per_count = {};
    }
    return state.video_selection_per_count;
}

function svbLoadState() {
    if (svbState && typeof svbState === 'object') return svbState;
    try {
        const raw = localStorage.getItem(SVB_STATE_KEY);
        if (raw) {
            const parsed = JSON.parse(raw);
            if (parsed && typeof parsed === 'object') {
                svbState = parsed;
            }
        }
    } catch (e) {
        svbState = {};
    }
    if (!svbState || typeof svbState !== 'object') {
        svbState = {};
    }
    return svbState;
}

function svbEnsureStateStructure() {
    const state = svbLoadState();
    if (!state || typeof state !== 'object') {
        svbState = {};
        return svbState;
    }
    if (!state.formData || typeof state.formData !== 'object') {
        state.formData = {};
    }
    if (!state.segments || typeof state.segments !== 'string') {
        state.segments = state.segments || '';
    }
    svbState = state;
    return svbState;
}

function svbSaveState() {
    if (!svbState || typeof svbState !== 'object') return;
    try {
        localStorage.setItem(SVB_STATE_KEY, JSON.stringify(svbState));
    } catch (e) {}
}

function svbUpdateState(patch) {
    const current = svbLoadState();
    svbState = Object.assign({}, current, patch || {});
    svbSaveState();
}

function svbClearState() {
    try { localStorage.removeItem(SVB_STATE_KEY); } catch (e) {}
    try {
        if (typeof SVB_PAYMENT_STORAGE !== 'undefined' && SVB_PAYMENT_STORAGE.step) {
            localStorage.removeItem(SVB_PAYMENT_STORAGE.step);
        }
    } catch (e) {}
    svbState = {};
}
// === УНИВЕРСАЛЬНЫЙ РЕНДЕР (ПК vs МОБИЛКА) ===
function svbRenderUI() {
    const container = document.getElementById('svb-dynamic-container');
    if (!container) return;

    const saved = svbLoadState();
    if (saved.child_count) {
        svbCurrentChildCount = parseInt(saved.child_count, 10) || svbCurrentChildCount;
    }

    const selectionMap = svbGetVideoSelectionMap();
    const preferredVideoId = selectionMap[svbCurrentChildCount] || saved.selected_video_id;
    if (preferredVideoId) {
        SVB_SELECTED_VIDEO_ID = preferredVideoId;
        const hInput = document.getElementById('selected_video_id');
        if (hInput) hInput.value = SVB_SELECTED_VIDEO_ID;
    }

    // Сохраняем текущие значения перед удалением (особенно важно для Select-ов)
    const currentValues = {};
    container.querySelectorAll('select, input').forEach(el => {
        if (el.name && el.type !== 'file' && el.type !== 'radio') {
            currentValues[el.name] = el.value;
        }
    });

    container.innerHTML = ''; // Очистка

    // ... (код логики availableVideos оставляем без изменений) ...
    const allTemplates = Object.entries(SVB_VIDEO_TEMPLATES);
    const availableVideos = allTemplates.filter(([vidId, data]) => {
        const allowed = data.for_children || [1];
        return allowed.includes(svbCurrentChildCount);
    });

    const isSelectedValid = availableVideos.some(([vidId]) => vidId === SVB_SELECTED_VIDEO_ID);
    let selectionChangedNotice = '';
    if (!isSelectedValid && availableVideos.length > 0) {
        SVB_SELECTED_VIDEO_ID = availableVideos[0][0];
        selectionChangedNotice = 'Відео недоступне для вибраної кількості дітей, обрано інше.';
        const hInput = document.getElementById('selected_video_id');
        if(hInput) hInput.value = SVB_SELECTED_VIDEO_ID;

        const selectionMap = svbGetVideoSelectionMap();
        selectionMap[svbCurrentChildCount] = SVB_SELECTED_VIDEO_ID;
        svbUpdateState({
            selected_video_id: SVB_SELECTED_VIDEO_ID,
            video_selection_per_count: selectionMap
        });
        svbSelectVideoTemplate(SVB_SELECTED_VIDEO_ID, false);
    }

    const isMobile = window.innerWidth <= 768;

    if (selectionChangedNotice) {
        const notice = document.createElement('div');
        notice.className = 'svb-video-notice';
        notice.textContent = selectionChangedNotice;
        notice.style.margin = '10px 0';
        notice.style.padding = '10px 12px';
        notice.style.background = '#fff8e1';
        notice.style.border = '1px solid #ffe08a';
        notice.style.borderRadius = '8px';
        container.appendChild(notice);
    }

    if (isMobile) {
        // === МОБИЛЬНАЯ ВЕРСИЯ ===
        const tplSection = document.createElement('div');
        tplSection.className = 'svb-template-section';

        const [activeId, activeData] = availableVideos.find(([vidId]) => vidId === SVB_SELECTED_VIDEO_ID) || availableVideos[0];
        const activePoster = activeData.image || 'https://placehold.co/600x340';

        const featHTML = `
            <div id="svb-featured-container" class="svb-featured-container">
                <div class="svb-feat-card">
                    <div class="svb-feat-img-wrap">
                        <img src="${activePoster}" class="svb-feat-thumb" alt="${activeData.label}">
                        <button type="button" class="svb-feat-btn" onclick="svbPreviewModal('${activeData.url}')">
                            Дивитися приклад
                        </button>
                    </div>
                    <div class="svb-feat-label">${activeData.label}</div>
                </div>
            </div>
            <h3 class="svb-section-title">Інші відео</h3>
            <div id="svb-others-slider" class="svb-others-slider"></div>
        `;
        tplSection.innerHTML = featHTML;
        container.appendChild(tplSection);

        const sliderContainer = tplSection.querySelector('#svb-others-slider');
        
        availableVideos.forEach(([vidId, data]) => {
            if (vidId === activeId) return; 

            const smallCard = document.createElement('div');
            smallCard.className = 'svb-small-card';
            const poster = data.image || 'https://placehold.co/600x340';

            smallCard.innerHTML = `
                <div style="position:relative;">
                    <img src="${poster}" class="svb-small-thumb" alt="${data.label}">
                </div>
                <div class="svb-small-label">${data.label}</div>
            `;

            smallCard.onclick = () => {
                SVB_SELECTED_VIDEO_ID = vidId;
                const hInput = document.getElementById('selected_video_id');
                if(hInput) hInput.value = vidId;
                const selectionMap = svbGetVideoSelectionMap();
                selectionMap[svbCurrentChildCount] = vidId;
                svbUpdateState({ selected_video_id: vidId, video_selection_per_count: selectionMap });
                svbSelectVideoTemplate(vidId, false);
                svbRenderUI();
            };

            sliderContainer.appendChild(smallCard);
        });

    } else {
        // === ПК ВЕРСИЯ ===
        const grid = document.createElement('div');
        grid.className = 'svb-desktop-grid';

        availableVideos.forEach(([vidId, data]) => {
            const isActive = (vidId === SVB_SELECTED_VIDEO_ID);
            const poster = data.image || 'https://placehold.co/600x340';

            const card = document.createElement('div');
            card.className = `svb-video-option ${isActive ? 'active' : ''}`;
            
            card.innerHTML = `
                <div class="svb-video-thumb-box">
                    <img src="${poster}" alt="${data.label}">
                    <button type="button" class="svb-video-btn-preview" onclick="event.stopPropagation(); svbPreviewModal('${data.url}')">
                        Дивитися приклад
                    </button>
                </div>
                <div class="svb-video-label-bar">${data.label}</div>
            `;

            card.onclick = () => {
                SVB_SELECTED_VIDEO_ID = vidId;
                const hInput = document.getElementById('selected_video_id');
                if(hInput) hInput.value = vidId;
                const selectionMap = svbGetVideoSelectionMap();
                selectionMap[svbCurrentChildCount] = vidId;
                svbUpdateState({ selected_video_id: vidId, video_selection_per_count: selectionMap });
                svbSelectVideoTemplate(vidId, false);
                svbRenderUI();
            };

            grid.appendChild(card);
        });
        
        container.appendChild(grid);
    }

    // === ВАЖНО: ПЕРЕЗАПУСК ЛОГИКИ ПОСЛЕ РЕНДЕРА ===
    svbReinitAfterRender(currentValues);
}

// Добавляем слушатель изменения размера экрана, чтобы перестраивать верстку на лету
window.addEventListener('resize', svbRenderUI);

// 2. Оновлена логіка вибору шаблону (завантаження даних у форми)
function svbSelectVideoTemplate(videoId, shouldRenderUI = true) {
    SVB_SELECTED_VIDEO_ID = videoId;
    const selectionMap = svbGetVideoSelectionMap();
    selectionMap[svbCurrentChildCount] = videoId;
    svbUpdateState({ selected_video_id: videoId, video_selection_per_count: selectionMap });
    
    // Оновлюємо hidden-поле
    const hiddenInput = document.getElementById('selected_video_id');
    if (hiddenInput) hiddenInput.value = videoId;

    // Завантажуємо відео в прев'ю на 2-му кроці
    const tpl = SVB_VIDEO_TEMPLATES[videoId];
    if (tpl && tpl.url) {
        document.querySelectorAll('.svb-vid-preview video').forEach(video => {
            try { video.pause(); } catch(e) {}
            video.src = tpl.url;
            video.currentTime = 0;
            video.load();
        });
    }

    // Оновлюємо таймінги
    if (typeof svbUpdateTimingsForVideo === 'function') {
        svbUpdateTimingsForVideo(videoId);
    }
    
    // Якщо викликано вручну (не з рендера), то оновити UI
    if (shouldRenderUI) {
        svbRenderUI();
    }
}

// 3. Оновлена логіка перемикача дітей
function svbBindChildCount() {
    const radios = document.querySelectorAll('input[name="child_count"]');
    const ageBlock = document.getElementById('svb-age-block');
    const field2 = document.querySelector('.field-child-2');
    const field3 = document.querySelector('.field-child-3');

    const handleCountChange = () => {
        const checked = document.querySelector('input[name="child_count"]:checked');
        svbCurrentChildCount = checked ? parseInt(checked.value) : 1;
        const selectionMap = svbGetVideoSelectionMap();
        if (selectionMap[svbCurrentChildCount]) {
            SVB_SELECTED_VIDEO_ID = selectionMap[svbCurrentChildCount];
            const hInput = document.getElementById('selected_video_id');
            if (hInput) hInput.value = SVB_SELECTED_VIDEO_ID;
        }
        svbUpdateState({
            child_count: svbCurrentChildCount,
            selected_video_id: SVB_SELECTED_VIDEO_ID,
            video_selection_per_count: selectionMap
        });

        // Показуємо/ховаємо поля
        if (svbCurrentChildCount > 1) {
            if (ageBlock) ageBlock.style.display = 'none';
        } else {
            if (ageBlock) ageBlock.style.display = 'block';
        }
        if (field2) field2.style.display = (svbCurrentChildCount >= 2) ? 'block' : 'none';
        if (field3) field3.style.display = (svbCurrentChildCount >= 3) ? 'block' : 'none';

        // Оновлюємо селекти аудіо (щоб сховати "Я один" для груп)
        if (typeof svbPopulateSelects === 'function') svbPopulateSelects();

        // ГОЛОВНЕ: Перемальовуємо відео-селектор
        svbRenderUI();
    };

    radios.forEach(r => r.addEventListener('change', handleCountChange));
    
    // Ініціалізація при старті
    handleCountChange();
}

// Допоміжна функція для модалки прев'ю
function svbPreviewModal(url) {
    const modal = document.getElementById('svb-video-modal');
    const v = document.getElementById('svb-modal-video');
    v.src = url;
    modal.classList.add('active');
    v.play().catch(()=>{});
}

// Функция перезапуска всех привязок после перерисовки HTML
function svbReinitAfterRender(savedValues = {}) {
    // 1. Заполняем списки заново (иначе они пустые)
    svbPopulateSelects(); 

    // 2. Восстанавливаем выбранные значения, если они были
    Object.entries(savedValues).forEach(([name, val]) => {
        const el = document.querySelector(`[name="${name}"]`);
        if (el) el.value = val;
    });

    // 3. Заново вешаем обработчики событий
    svbBindAudioPreview();
    // Обработчики фото вешаем только если элементы существуют (шаг 2 может быть скрыт, но inputs в DOM есть)
    svbBindPhotoInputs(); 
    svbEnsureWrappers();
    svbBindNumericControls();
    svbInitIntervalUi();
    svbBindIntervalUi();
    svbBindRealtimeControls();

    // 4. Поиск имен (самое важное для имен)
      svbBindNameSuggestUniversal('name_text', 'svb-name-suggest', 'name_audio', 'svb-name-display-1', 'svb-name-play-1');
      svbBindNameSuggestUniversal('name_text_2', 'svb-name-suggest-2', 'name_audio_2', 'svb-name-display-2', 'svb-name-play-2');
      svbBindNameSuggestUniversal('name_text_3', 'svb-name-suggest-3', 'name_audio_3', 'svb-name-display-3', 'svb-name-play-3');

      // 5. Кнопки прослушивания имен
      svbBindNamePlayButtons();
  }

document.addEventListener('DOMContentLoaded', () => {
    console.log('🚀 SVB Init started (Final Fix Rebind)');
    svbLoadState();

    svbDebugLog('page_boot', {
      state_version: (window.SVB_DATA && window.SVB_DATA.debug && window.SVB_DATA.debug.state_version) || null,
      order_id: (window.SVB_DATA && window.SVB_DATA.debug && window.SVB_DATA.debug.order_id) || null,
      payment_status: (window.SVB_DATA && window.SVB_DATA.debug && window.SVB_DATA.debug.payment_status) || '',
      token: (window.SVB_DATA && window.SVB_DATA.debug && window.SVB_DATA.debug.public_token_masked) || '',
    });

    // 1. Ініціалізуємо змінну кількості дітей перед усім іншим
    const checked = document.querySelector('input[name="child_count"]:checked');
    svbCurrentChildCount = checked ? parseInt(checked.value) : 1;
    if (svbState && svbState.child_count) {
        const savedRadio = document.querySelector(`input[name="child_count"][value="${svbState.child_count}"]`);
        if (savedRadio) {
            savedRadio.checked = true;
            svbCurrentChildCount = parseInt(svbState.child_count, 10) || svbCurrentChildCount;
        }
    }

    if (svbState) {
        const map = svbGetVideoSelectionMap();
        const initialVid = map[svbCurrentChildCount] || svbState.selected_video_id;
        if (initialVid) {
            SVB_SELECTED_VIDEO_ID = initialVid;
            const hInput = document.getElementById('selected_video_id');
            if (hInput) hInput.value = initialVid;
        }
    }

    // 2. Навішуємо обробник на перемикач кількості дітей
    // Це головний тригер: при зміні він викликає svbRenderUI, який перебудовує все
    const radios = document.querySelectorAll('input[name="child_count"]');
        radios.forEach(r => r.addEventListener('change', () => {
            const c = document.querySelector('input[name="child_count"]:checked');
            svbCurrentChildCount = c ? parseInt(c.value) : 1;
            const selectionMap = svbGetVideoSelectionMap();
            if (selectionMap[svbCurrentChildCount]) {
                SVB_SELECTED_VIDEO_ID = selectionMap[svbCurrentChildCount];
                const hInput = document.getElementById('selected_video_id');
                if (hInput) hInput.value = SVB_SELECTED_VIDEO_ID;
            }
            svbUpdateState({
                child_count: svbCurrentChildCount,
                selected_video_id: SVB_SELECTED_VIDEO_ID,
                video_selection_per_count: selectionMap
            });
        
        // Керування видимістю полів (для надійності дублюємо тут, хоча reinit теж це робить)
        const ageBlock = document.getElementById('svb-age-block');
        const field2 = document.querySelector('.field-child-2');
        const field3 = document.querySelector('.field-child-3');

        if (svbCurrentChildCount > 1) {
            if (ageBlock) ageBlock.style.display = 'none';
        } else {
            if (ageBlock) ageBlock.style.display = 'block';
        }
        if (field2) field2.style.display = (svbCurrentChildCount >= 2) ? 'block' : 'none';
        if (field3) field3.style.display = (svbCurrentChildCount >= 3) ? 'block' : 'none';

        // Перемальовуємо інтерфейс (це автоматично викличе svbReinitAfterRender)
        svbRenderUI();
    }));

      // 3. Первинний рендер інтерфейсу при завантаженні сторінки
      // Ця функція тепер сама викличе svbReinitAfterRender() і все підключить
      svbRenderUI();

      const lookupBtn = document.getElementById('svb-lookup-submit');
      if (lookupBtn) {
          lookupBtn.addEventListener('click', svbLookupExistingVideo);
      }

      // 4. Логіка попапу запиту імені (статичний елемент, тому залишається тут)
      const reqSubmit = document.getElementById('popup_req_submit');
      if (reqSubmit) {
        reqSubmit.addEventListener('click', () => {
            const nameVal = document.getElementById('popup_req_name').value.trim();
            const emailVal = document.getElementById('popup_req_email').value.trim();

            if (!nameVal || !emailVal) {
                alert('Будь ласка, заповніть обидва поля (Ім\'я та Email).');
                return;
            }

            const originalBtnText = reqSubmit.textContent;
            reqSubmit.disabled = true;
            reqSubmit.textContent = 'Відправка...';

            const fd = new FormData();
            fd.append('action', 'svb_request_name');
            fd.append('_svb_nonce', SVB_AJAX.nonce);
            fd.append('name_req', nameVal);
            fd.append('email_req', emailVal);

            fetch(SVB_AJAX.url, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        alert(res.data);
                        document.getElementById('svb-name-popup').classList.remove('active');
                        document.getElementById('popup_req_name').value = '';
                        document.getElementById('popup_req_email').value = '';
                    } else {
                        alert('Помилка: ' + (res.data || 'Unknown error'));
                    }
                }) 
                .catch(err => { 
                    alert('Помилка з\'єднання'); 
                    console.error(err);
                })
                .finally(() => {
                    reqSubmit.disabled = false;
                    reqSubmit.textContent = originalBtnText;
                });
        });
    }

    svbRestoreStep2State();
    svbCheckInvoiceOnReturn();

    const formEl = document.getElementById('svb-form');
    if (formEl) {
        formEl.addEventListener('change', () => {
            svbPersistStep2State();
        });
    }

    const paymentToggle = document.querySelector('#svb_payment_toggle');
    if (paymentToggle && typeof SVB_PAYMENT.enabled !== 'undefined') {
        paymentToggle.checked = !!SVB_PAYMENT.enabled;
    }

    // 5. Страховка: примусово оновлюємо прев'ю через мить, щоб переконатися, що DOM готовий
    setTimeout(() => {
        // Вибираємо правильне відео
        if (typeof SVB_SELECTED_VIDEO_ID !== 'undefined' && SVB_SELECTED_VIDEO_ID) {
            svbSelectVideoTemplate(SVB_SELECTED_VIDEO_ID, false);
        } else {
            svbSelectVideoTemplate('video1', false);
        }
        
        // Імітуємо подію зміни, щоб коректно відпрацювала видимість полів
        const event = new Event('change');
        if(checked) checked.dispatchEvent(event);
        
        // Оновлюємо трансформації прев'ю картинок (щоб вони стали на місця)
        ['child1', 'child2', 'parent1', 'parent2'].forEach(key => {
            svbUpdatePreviewTransform(key);
        });
    }, 200);
});
// Авто-скрол до активного відео на мобільному
const activeCard = document.querySelector('.svb-video-option.active');
if(activeCard && window.innerWidth < 768) {
    activeCard.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
}

const SVB_AUDIO = (window.SVB_DATA && window.SVB_DATA.audio) ? window.SVB_DATA.audio : {};

const SVB_VIDEO_TEMPLATES = (window.SVB_DATA && window.SVB_DATA.video_templates)
    ? window.SVB_DATA.video_templates
    : {};

const SVB_TEMPLATE_TIMINGS = (window.SVB_DATA && window.SVB_DATA.template_timings)
    ? window.SVB_DATA.template_timings
    : {};

const SVB_PAYMENT = (window.SVB_DATA && window.SVB_DATA.payment)
    ? window.SVB_DATA.payment
    : {};
let svbPaymentReturnHandled = false;
let svbPaymentStatus = SVB_PAYMENT.status || 'unpaid';

function svbIsDebugMode() {
    try {
        return !!(
            (typeof SVB_DEBUG !== 'undefined' && SVB_DEBUG) ||
            (typeof window !== 'undefined' && window.SVB_DEBUG === true) ||
            (SVB_PAYMENT && SVB_PAYMENT.is_admin)
        );
    } catch (e) {
        return false;
    }
}

function svbLog(tag, obj) {
    if (!svbIsDebugMode()) return;
    if (typeof tag === 'string' && tag.startsWith('[SVB')) {
        console.log(tag, obj);
        return;
    }
    if (typeof obj === 'undefined') {
        console.log(`[SVB PAY][${tag}]`);
        return;
    }
    console.log(`[SVB PAY][${tag}]`, obj);
}

function svbLogRecover() {
    if (!svbIsDebugMode()) return;
    const args = Array.from(arguments);
    args.unshift('[SVB REC]');
    console.log.apply(console, args);
}

function svbError() {
    if (!svbIsDebugMode()) return;
    const args = Array.from(arguments);
    args.unshift('[SVB PAY]');
    console.error.apply(console, args);
}

function svbMaskInvoiceId(invoiceId) {
    if (!invoiceId) return '';
    const id = String(invoiceId);
    if (id.length <= 6) return id;
    return `${id.slice(0, 6)}***${id.slice(-4)}`;
}

async function svbFetchSessionDebug(label = 'init') {
    if (!SVB_AJAX || !SVB_AJAX.nonce) return;
    const fd = new FormData();
    fd.append('action', 'svb_debug_session');
    fd.append('_svb_nonce', SVB_AJAX.nonce);

    try {
        const res = await fetch(SVB_AJAX.url, { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await res.json();
        console.groupCollapsed('[SVB SESSION][DEBUG]', label);
        console.log(data.data || data);
        console.groupEnd();
        return data.data || {};
    } catch (e) {
        console.error('[SVB SESSION][DEBUG] failed', e);
        return null;
    }
}

function svbMaskToken(tkn) {
    if (!tkn) return '';
    const token = String(tkn);
    if (token.length <= 6) return token;
    if (token.length <= 12) return token.slice(0, 3) + '***' + token.slice(-2);
    return token.slice(0, 6) + '***' + token.slice(-4);
}

function svbMaskEmail(rawEmail) {
    if (!rawEmail) return { masked: '', normalized: '' };
    const normalized = (rawEmail || '').toString().toLowerCase().trim();
    if (!normalized) return { masked: '', normalized: '' };
    const parts = normalized.split('@');
    if (parts.length !== 2) {
        return { masked: normalized, normalized };
    }

    const user = parts[0];
    const domain = parts[1];
    const maskedUser = user.length <= 2 ? user : `${user.slice(0, 1)}***${user.slice(-1)}`;
    const maskedDomain = domain.length <= 3 ? domain : `${domain.slice(0, 1)}***${domain.slice(-1)}`;

    return {
        masked: `${maskedUser}@${maskedDomain}`,
        normalized
    };
}

function svbMaskUrlToken(url) {
    try {
        const u = new URL(url, window.location.origin);
        const token = u.searchParams.get('token');
        if (token) {
            u.searchParams.set('token', svbMaskToken(token));
        }
        return u.toString();
    } catch (e) {
        return url;
    }
}

async function svbHashFileSha256(file) {
    if (!file || typeof file.arrayBuffer !== 'function') return '';
    try {
        const buffer = await file.arrayBuffer();
        const digest = await crypto.subtle.digest('SHA-256', buffer);
        return Array.from(new Uint8Array(digest)).map(b => b.toString(16).padStart(2, '0')).join('');
    } catch (e) {
        svbError('[SVB GATE] hash failed, falling back to name+size', e);
        return `${file.name || 'file'}-${file.size || 0}`;
    }
}

async function svbCollectPhotoHashes() {
    const hashes = [];
    const inputs = document.querySelectorAll('input[type="file"][name^="photo_"]');
    for (const input of inputs) {
        const file = input.files && input.files[0];
        if (file) {
            const hash = await svbHashFileSha256(file);
            if (hash) {
                hashes.push(hash);
            }
        }
    }
    return hashes;
}

async function svbComputeFingerprint(payload, photoHashes = []) {
    const normalized = JSON.stringify(payload || {});
    const hashes = (photoHashes || []).map(h => String(h)).sort();
    const raw = `${normalized}|${hashes.join('|')}`;
    const encoder = new TextEncoder();
    const digest = await crypto.subtle.digest('SHA-256', encoder.encode(raw));
    return Array.from(new Uint8Array(digest)).map(b => b.toString(16).padStart(2, '0')).join('');
}

function svbLogDownload() {
    if (!svbIsDebugMode()) return;
    const args = Array.from(arguments);
    args.unshift('[SVB DL]');
    console.log.apply(console, args);
}

function svbErrorDownload() {
    if (!svbIsDebugMode()) return;
    const args = Array.from(arguments);
    args.unshift('[SVB DL]');
    console.error.apply(console, args);
}

/* === НАЛАШТУВАННЯ ПРОПОРЦІЙ (ASPECT RATIO) === */
const SVB_ASPECT_RATIOS = {
    // ЗАМЕНИТЕ NaN на конкретные пропорции, чтобы запретить изменение рамки!
    
    // 1. Фабрика Іграшок
    video1: { child1: 1/1, child2: 1/1, parent1: 3/4, parent2: 3/4 },
    
    // 2. Чарівна Кухня
    video2: { child1: 16/9, child2: 16/10, parent1: 16/9, parent2: 16/9 },
    
    // 3. Казкові Санчата
    video3: { child1: 16/9,  child2: 16/9,   parent1: 3/4, parent2: 3/4 },
    
    // 4. Містечко Чудес
    video4: { child1: 3/4, child2: 3/4, parent1: 3/4, parent2: 3/4 },

    // Групові відео
    video5: { child1: 1/1, child2: 1/1, parent1: 3/4, parent2: 3/4 }, 
    video6: { child1: 16/9, child2: 16/10, parent1: 16/9, parent2: 16/9 },
    video7: { child1: 16/9, child2: 16/10, parent1: 3/4, parent2: 3/4 },
    video8: { child1: 3/4, child2: 3/4, parent1: 3/4, parent2: 3/4 },
};

let SVB_SCENES_DATA = {}; 
let SVB_CURRENT_SCENE_INDEX = {
    child1: 0, child2: 0, parent1: 0, parent2: 0
};
function svbSwitchScene(key, index) {
    const oldIdx = SVB_CURRENT_SCENE_INDEX[key];
    svbSaveInputsToScene(key, oldIdx);

    SVB_CURRENT_SCENE_INDEX[key] = index;

    svbLoadInputsFromScene(key, index);
    
    if (SVB_SCENES_DATA[key] && SVB_SCENES_DATA[key][index]) {
        const timeStr = SVB_SCENES_DATA[key][index].start; 
        const vid = document.getElementById('svb-video-'+key);
        
        if (vid && timeStr) {
            const seconds = svbTSToSeconds(timeStr); 
            if (seconds !== null) {
                vid.currentTime = seconds; 
            }
        }
    }
    svbUpdatePreviewTransform(key);
}

function svbSaveInputsToScene(key, index) {
    if (!SVB_SCENES_DATA[key] || !SVB_SCENES_DATA[key][index]) return;
    const data = SVB_SCENES_DATA[key][index];
    
    // Добавили pleft и pright в список
    ['x','y','scale','scale_x','scale_y','skew','skew_y','angle','radius','opacity','glow','pleft','pright'].forEach(param => {
        const inp = document.querySelector(`input[name="${key}_${param}"]`);
        if(inp) data[param] = parseFloat(inp.value) || 0;
    });
}

function svbLoadInputsFromScene(key, index) {
    if (!SVB_SCENES_DATA[key] || !SVB_SCENES_DATA[key][index]) return;
    const data = SVB_SCENES_DATA[key][index];

    // Добавили pleft и pright
    ['x','y','scale','scale_x','scale_y','skew','skew_y','angle','radius','opacity','glow','pleft','pright'].forEach(param => {
        const val = data[param] !== undefined ? data[param] : 0;
        
        const numInp = document.getElementById(`val-${key}-${param.replace('_','-')}`);
        if(numInp) numInp.value = val;
        
        const rangeInp = document.querySelector(`input[name="${key}_${param}"]`);
        if(rangeInp) rangeInp.value = val;
    });
    
    svbUpdatePreviewTransform(key);
}
function svbUpdateTimingsForVideo(videoId) {
    const tpl = SVB_VIDEO_TEMPLATES[videoId];
    if (!tpl || !tpl.scenes) return;

    console.log('🎬 Loading scenes for:', videoId);

    // Очищаем, используем let переменную
    SVB_OVERLAY_WINDOWS = {};
    SVB_SCENES_DATA = {};

    ['child1','child2','parent1','parent2'].forEach(key => {
        const scenes = tpl.scenes[key] || [];
        
        // 1. Обновляем тайминги (для плеера)
        SVB_OVERLAY_WINDOWS[key] = scenes.map(s => [
            svbTSToSeconds(s.start), 
            svbTSToSeconds(s.end)
        ]);

        // 2. Инициализируем геометрию (X, Y, Scale...) из конфига
        SVB_SCENES_DATA[key] = scenes.map(s => ({
            // Если в конфиге есть значение, берем его, иначе дефолт 0 или 100
            x:       s.x !== undefined ? parseFloat(s.x) : 0,
            y:       s.y !== undefined ? parseFloat(s.y) : 0,
            scale:   s.scale !== undefined ? parseFloat(s.scale) : 100,
            scale_x: s.scale_x !== undefined ? parseFloat(s.scale_x) : 100,
            scale_y: s.scale_y !== undefined ? parseFloat(s.scale_y) : 100,
            skew:    s.skew !== undefined ? parseFloat(s.skew) : 0,
            skew_y:  s.skew_y !== undefined ? parseFloat(s.skew_y) : 0,
            angle:   s.angle !== undefined ? parseFloat(s.angle) : 0,
            radius:  s.radius !== undefined ? parseFloat(s.radius) : 0,
            opacity: s.opacity !== undefined ? parseFloat(s.opacity) : 100,
            glow:    s.glow !== undefined ? parseFloat(s.glow) : 0,
            
            // Сохраняем оригинальные строки времени для JSON
            start: svbSecondsToTS(svbTSToSeconds(s.start)),
            end:   svbSecondsToTS(svbTSToSeconds(s.end)),
            label: s.label
        }));

        // 3. Обновляем UI селектора сцен (Сцена 1, Сцена 2)
        const sel = document.querySelector(`.svb-scene-select[data-key="${key}"]`);
        if (sel) {
            sel.innerHTML = scenes.map((s, i) => 
                `<option value="${i}">${s.label || ('Сцена '+(i+1))} (${s.start})</option>`
            ).join('');
            sel.value = 0;
            SVB_CURRENT_SCENE_INDEX[key] = 0;
            
            const newSel = sel.cloneNode(true);
            sel.parentNode.replaceChild(newSel, sel);
            newSel.addEventListener('change', (e) => {
                svbSwitchScene(key, parseInt(e.target.value));
            });
        }
        
        // Бинд кнопки "глаз"
        const jumpBtn = document.querySelector(`.svb-scene-jump[data-key="${key}"]`);
        if(jumpBtn) {
             const newJump = jumpBtn.cloneNode(true);
             jumpBtn.parentNode.replaceChild(newJump, jumpBtn);
             newJump.addEventListener('click', () => {
                 const idx = SVB_CURRENT_SCENE_INDEX[key];
                 const time = SVB_SCENES_DATA[key][idx].start;
                 const vid = document.getElementById('svb-video-'+key);
                 // start хранится как строка, конвертируем для плеера
                 if(vid && time) vid.currentTime = svbTSToSeconds(time);
             });
        }
    });

    // === ГЛАВНОЕ ИСПРАВЛЕНИЕ: ПЕРЕРИСОВАТЬ ИНТЕРВАЛЫ ===
    // Теперь, когда SVB_OVERLAY_WINDOWS обновлен, обновляем HTML инпуты
    svbInitIntervalUi();
    svbBindIntervalUi();

    // Применяем значения первой сцены к инпутам (X, Y...)
    Object.keys(SVB_CURRENT_SCENE_INDEX).forEach(key => svbLoadInputsFromScene(key, 0));
}

// Функція закриття модального вікна
function svbCloseModal(e, force = false) {
    const modal = document.getElementById('svb-video-modal');
    const content = document.querySelector('.svb-modal-content');
    
    // Закриваємо, якщо клік по фону (modal) або примусово (хрестик)
    // e.target === modal гарантує, що ми не закриємо, якщо клікнули по самому відео
    if (force || e.target === modal) {
        const v = document.getElementById('svb-modal-video');
        v.pause(); // Зупиняємо відео
        modal.classList.remove('active');
    }
}


// Текущее выбранное видео
let SVB_SELECTED_VIDEO_ID = (window.SVB_DATA && window.SVB_DATA.selected_video_id)
    ? window.SVB_DATA.selected_video_id
    : 'video1';

const SVB_AJAX  = {
    url: (window.SVB_DATA && window.SVB_DATA.ajax_url) ? window.SVB_DATA.ajax_url : '',
    nonce: (window.SVB_DATA && window.SVB_DATA.nonce) ? window.SVB_DATA.nonce : '',
    video_template: (window.SVB_DATA && window.SVB_DATA.template_url) ? window.SVB_DATA.template_url : ''
};

const SVB_PROCESSED_PHOTO_SIZE = (window.SVB_DATA && window.SVB_DATA.processed_photo_size)
    ? window.SVB_DATA.processed_photo_size
    : 709;
const SVB_PREVIEW_CAPS = (window.SVB_DATA && window.SVB_DATA.preview_caps) ? window.SVB_DATA.preview_caps : {};

// ИСПРАВЛЕНИЕ: Используем let, так как эта переменная перезаписывается при смене шаблона
let SVB_OVERLAY_WINDOWS = (window.SVB_DATA && window.SVB_DATA.overlay_windows)
    ? window.SVB_DATA.overlay_windows
    : {};

const SVB_OVERLAY_WINDOWS_DEFAULTS = JSON.parse(JSON.stringify(SVB_OVERLAY_WINDOWS));
const SVB_INTERVAL_UI_KEYS = ['child1', 'child2', 'parents'];

// === ФУНКЦІЇ ЧАСУ (3 ЗНАКИ МІЛІСЕКУНД) ===
function svbSecondsToTS(sec) {
    sec = Math.max(0, Number(sec) || 0);
    const totalMs = Math.round(sec * 1000);
    const ms = totalMs % 1000; // 3 знаки
    const totalSec = (totalMs - ms) / 1000;
    const mm = Math.floor(totalSec / 60);
    const ss = totalSec % 60;
    // Вивід: 00:54:677
    return String(mm).padStart(2, '0') + ':' +
           String(ss).padStart(2, '0') + ':' +
           String(ms).padStart(3, '0');
}

function svbTSToSeconds(str) {
    if (!str) return null;
    const s = String(str).trim();
    
    // Підтримка MM:SS.mmm (крапка - стандарт)
    let m = s.match(/^(\d{1,2}):(\d{2})\.(\d+)$/);
    if (m) {
        // ділимо на 10 в степені довжини (щоб .5 = 500ms, .05 = 50ms)
        let msPart = parseFloat("0." + m[3]);
        return parseInt(m[1])*60 + parseInt(m[2]) + msPart;
    }

    // Підтримка MM:SS:mmm (3 знаки після двокрапки)
    m = s.match(/^(\d{1,2}):(\d{2}):(\d{3})$/);
    if (m) {
        return parseInt(m[1])*60 + parseInt(m[2]) + parseInt(m[3])/1000;
    }
    
    // Старий формат (2 знаки - соті)
    m = s.match(/^(\d{1,2}):(\d{2}):(\d{2})$/);
    if (m) {
        return parseInt(m[1])*60 + parseInt(m[2]) + parseInt(m[3])/100;
    }
    
    // Просто секунди
    if (!isNaN(parseFloat(s)) && isFinite(s)) {
        return parseFloat(s);
    }

    return 0;
}

function svbCreateIntervalRow(startStr, endStr) {
    const row = document.createElement('div');
    row.className = 'svb-int-row';
    // Додаємо приклад з мілісекундами у placeholder
    row.innerHTML = `
        <input type="text" class="svb-input svb-int-start" placeholder="00:54:20.500">
        <span>–</span>
        <input type="text" class="svb-input svb-int-end" placeholder="00:58:25.000">
        <button type="button" class="svb-btn ghost svb-int-del">✕</button>
    `;
    if (typeof startStr === 'string') {
        row.querySelector('.svb-int-start').value = startStr;
    }
    if (typeof endStr === 'string') {
        row.querySelector('.svb-int-end').value = endStr;
    }
    return row;
}

function svbInitIntervalUi() {
    SVB_INTERVAL_UI_KEYS.forEach(uiKey => {
        const box = document.querySelector(`.svb-intervals[data-key="${uiKey}"]`);
        if (!box) return;
        const rowsWrap = box.querySelector('.svb-intervals-rows');
        if (!rowsWrap) return;
        rowsWrap.innerHTML = '';

        // UI ключ 'parents' соответствует данным в 'parent1' (или 'parents' если мы его добавили выше)
        let srcKey = uiKey;
        if (uiKey === 'parents') srcKey = 'parent1'; 

        const arr = (SVB_OVERLAY_WINDOWS && SVB_OVERLAY_WINDOWS[srcKey]) || [];

        if (!arr.length) {
            rowsWrap.appendChild(svbCreateIntervalRow());
        } else {
            arr.forEach(pair => {
                rowsWrap.appendChild(
                    svbCreateIntervalRow(
                        svbSecondsToTS(pair[0] || 0),
                        svbSecondsToTS(pair[1] || 0)
                    )
                );
            });
        }
    });
}

function svbRebuildWindowsFromUi(uiKey) {
    const box = document.querySelector(`.svb-intervals[data-key="${uiKey}"]`);
    if (!box) return;
    const rows = box.querySelectorAll('.svb-int-row');
    const parsed = [];

    rows.forEach(row => {
        const startStr = row.querySelector('.svb-int-start').value;
        const endStr   = row.querySelector('.svb-int-end').value;
        const s = svbTSToSeconds(startStr);
        const e = svbTSToSeconds(endStr);
        if (s !== null && e !== null && e > s) {
            parsed.push([s, e]);
        }
    });

    if (!parsed.length) {
        if (uiKey === 'parents') {
            SVB_OVERLAY_WINDOWS.parent1 = JSON.parse(JSON.stringify(SVB_OVERLAY_WINDOWS_DEFAULTS.parent1 || []));
            SVB_OVERLAY_WINDOWS.parent2 = JSON.parse(JSON.stringify(SVB_OVERLAY_WINDOWS_DEFAULTS.parent2 || []));
        } else if (SVB_OVERLAY_WINDOWS_DEFAULTS[uiKey]) {
            SVB_OVERLAY_WINDOWS[uiKey] = JSON.parse(JSON.stringify(SVB_OVERLAY_WINDOWS_DEFAULTS[uiKey]));
        }
        return;
    }

    if (uiKey === 'parents') {
        SVB_OVERLAY_WINDOWS.parent1 = parsed;
        SVB_OVERLAY_WINDOWS.parent2 = parsed.map(p => [p[0], p[1]]);
    } else {
        SVB_OVERLAY_WINDOWS[uiKey] = parsed;
    }
}

function svbBindIntervalUi() {
    SVB_INTERVAL_UI_KEYS.forEach(uiKey => {
        const box = document.querySelector(`.svb-intervals[data-key="${uiKey}"]`);
        if (!box || box.__svb_bound) return;
        const rowsWrap = box.querySelector('.svb-intervals-rows');
        const addBtn   = box.querySelector(`.svb-int-add[data-key="${uiKey}"]`);
        const resetBtn = box.querySelector(`.svb-int-reset[data-key="${uiKey}"]`);

        if (addBtn) {
            addBtn.addEventListener('click', () => {
                if (!rowsWrap) return;
                rowsWrap.appendChild(svbCreateIntervalRow());
            });
        }
        if (resetBtn) {
            resetBtn.addEventListener('click', () => {
                if (uiKey === 'parents') {
                    SVB_OVERLAY_WINDOWS.parent1 = JSON.parse(JSON.stringify(SVB_OVERLAY_WINDOWS_DEFAULTS.parent1 || []));
                    SVB_OVERLAY_WINDOWS.parent2 = JSON.parse(JSON.stringify(SVB_OVERLAY_WINDOWS_DEFAULTS.parent2 || []));
                } else if (SVB_OVERLAY_WINDOWS_DEFAULTS[uiKey]) {
                    SVB_OVERLAY_WINDOWS[uiKey] = JSON.parse(JSON.stringify(SVB_OVERLAY_WINDOWS_DEFAULTS[uiKey]));
                }
                svbInitIntervalUi();
            });
        }

        box.addEventListener('input', (e) => {
            if (e.target.classList.contains('svb-int-start') ||
                e.target.classList.contains('svb-int-end')) {
                svbRebuildWindowsFromUi(uiKey);
            }
        });

        if (rowsWrap) {
            rowsWrap.addEventListener('click', (e) => {
                if (e.target.classList.contains('svb-int-del')) {
                    const row = e.target.closest('.svb-int-row');
                    if (row) {
                        row.remove();
                        svbRebuildWindowsFromUi(uiKey);
                    }
                }
            });
        }

        box.__svb_bound = true;
    });
}

function svbSerializeSegmentsToField() {
    const segments = {
        child1: [],
        child2: [],
        parents: [],
    };

    const map = {
        child1: 'child1',
        child2: 'child2',
        parents: 'parent1', // общий для обоих родителей
    };

    Object.keys(map).forEach(uiKey => {
        const srcKey = map[uiKey];
        const arr = (SVB_OVERLAY_WINDOWS && SVB_OVERLAY_WINDOWS[srcKey]) || [];
        segments[uiKey] = arr.map(pair => [
            svbSecondsToTS(pair[0] || 0),
            svbSecondsToTS(pair[1] || 0)
        ]);
    });

    const field = document.getElementById('svb_segments');
    if (field) {
        field.value = JSON.stringify(segments);
    }
}



const $  = (sel,root=document) => root.querySelector(sel);
const $$ = (sel,root=document) => Array.from(root.querySelectorAll(sel));

function svbScrollToTop() {
  const target = document.querySelector('.svb-wrap') || document.querySelector('.svb-card');
  if (target && typeof target.scrollIntoView === 'function') {
    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
  } else {
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
}

let svbCurrentSampleAudio = null;
function svbFormatTime(seconds) {
    const totalMs = Math.round(seconds * 1000);
    const ms = totalMs % 1000;
    const totalSec = (totalMs - ms) / 1000;
    const min = Math.floor(totalSec / 60);
    const sec = totalSec % 60;
    return (
        `${min.toString().padStart(2, '0')}:` +
        `${sec.toString().padStart(2, '0')}.` +
        `${ms.toString().padStart(3, '0')}`
    );
}



function svbSetStep(n){
  const prevState = svbLoadState();
  const prevStep = prevState && prevState.step ? prevState.step : null;
  $$('.svb-step').forEach(s=>s.classList.remove('active'));
  $(`.svb-step[data-step="${n}"]`).classList.add('active');
  for(let i=1;i<=3;i++){
    const dot = $(`#svb-dot-${i}`);
    dot.classList.toggle('active', i===n);
    dot.classList.toggle('muted', i!==n);
  }
  const titles = {1:'Крок 1 — Дані дитини', 2:'Крок 2 — Фото', 3:'Крок 3 — Підтвердження та отримання'};
  $('#svb-title').textContent = titles[n] || '';
  svbUpdateState({ step: n });
  console.log('[SVB UI][STEP]', { from: prevStep, to: n, reason: 'user_flow' });
  svbScrollToTop();
}
// === ФУНКЦИЯ ФИЛЬТРАЦИИ АУДИО (ФИНАЛЬНАЯ) ===
function svbPopulateSelects() {
    // 1. Получаем выбранное количество детей
    const radios = document.querySelector('input[name="child_count"]:checked');
    const count = radios ? parseInt(radios.value) : 1;
    
    // Режим группы: если выбрано 2 или 3 ребенка
    const isGroup = count > 1; 

    // Отладка в консоль
    console.log(`🎬 SVB: Перерисовка списков. Детей: ${count}, Режим группы: ${isGroup}`);

    // 2. Ищем все выпадающие списки (кроме имен)
    const selects = document.querySelectorAll('select[data-cat]');
    
    selects.forEach(sel => {
        const cat = sel.getAttribute('data-cat');
        if (cat === 'name') return; // Имена пропускаем
        
        // Берем данные из глобальной переменной
        const allItems = (typeof SVB_AUDIO !== 'undefined' && SVB_AUDIO[cat]) ? SVB_AUDIO[cat] : [];
        
        // 3. ФИЛЬТРАЦИЯ
        const filteredItems = allItems.filter(item => {
            // Если type не указан, считаем его 'single'
            const itemType = item.type ? item.type : 'single';

            if (isGroup) {
                // Если группа (2-3) -> показываем ТОЛЬКО type="group"
                return itemType === 'group';
            } else {
                // Если 1 ребенок -> показываем ТОЛЬКО type="single" (или пустой)
                return itemType !== 'group';
            }
        });

        // 4. Отрисовка
        const currentVal = sel.value; // Запоминаем выбор
        sel.innerHTML = ''; // Очищаем

        if (filteredItems.length > 0) {
            // Пустая опция в начале
            const defaultOpt = document.createElement('option');
            defaultOpt.value = "";
            defaultOpt.textContent = "Оберіть варіант...";
            sel.appendChild(defaultOpt);

            filteredItems.forEach(i => {
                const opt = document.createElement('option');
                opt.value = i.file;
                opt.textContent = i.label;
                if (i.file === currentVal) opt.selected = true;
                sel.appendChild(opt);
            });
        } else {
            const opt = document.createElement('option');
            opt.textContent = "— немає варіантів —";
            sel.appendChild(opt);
        }

        // Если старый выбор исчез из-за фильтра, сбрасываем
        if (sel.selectedIndex === -1) sel.selectedIndex = 0;
    });
}

function svbBindAudioPreview(){
  $$('.svb-play').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const cat = btn.getAttribute('data-play');
      const sel = document.querySelector(`select[name="${cat}_audio"]`);
      if(!sel) return;
      
      const file = sel.value;
      
      // ВИПРАВЛЕННЯ: Тут теж міняємо на getAllNames()
      let items = (cat === 'name') ? getAllNames() : (SVB_AUDIO[cat]||[]);
      
      const item = items.find(i=>i.file===file);
      if(item){ 
        if(svbCurrentSampleAudio) {
            svbCurrentSampleAudio.pause();
            svbCurrentSampleAudio = null;
        }
        const a = new Audio(item.url); 
        a.play();
        svbCurrentSampleAudio = a; 
      }
    });
  });
}

let svbCropper = null;
let svbCurrentInput = null;
let svbCurrentKey = null;
let svbCurrentPreviewUrl = null;

function svbIsHeicFile(file) {
    if (!file) return false;
    const name = (file.name || '').toLowerCase();
    const type = (file.type || '').toLowerCase();
    const isHeicMime = ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence'].includes(type);
    const isHeicExt = name.endsWith('.heic') || name.endsWith('.heif');
    return isHeicMime || isHeicExt;
}

async function svbNormalizeImageFile(file) {
    if (!svbIsHeicFile(file)) return file;
    if (typeof heic2any !== 'function') {
        throw new Error('HEIC converter is not available');
    }
    const baseName = (file.name || 'upload').replace(/\.[^.]+$/, '');
    const blob = await heic2any({ blob: file, toType: 'image/jpeg', quality: 0.92 });
    return new File([blob], `${baseName}.jpg`, { type: 'image/jpeg' });
}

function svbCloseCrop() {
    const modal = document.getElementById('svb-crop-modal');
    modal.style.display = 'none';
    modal.classList.remove('active');
    if (svbCropper) {
        svbCropper.destroy();
        svbCropper = null;
    }
    // Очистити input, якщо скасували, щоб можна було вибрати той самий файл знову
    if (svbCurrentInput && !svbCurrentInput.dataset.cropped) {
        svbCurrentInput.value = ''; 
    }
}
function svbBindPhotoInputs() {
    console.log('🚀 svbBindPhotoInputs init');

    // 1. Биндим числовые инпуты (X, Y, Scale...)
    ['child1', 'child2', 'parent1', 'parent2'].forEach(key => {
        if (typeof svbMarkTouched === 'function') svbMarkTouched(key);

        ['x', 'y', 'scale','scale_x', 'scale_y', 'skew', 'skew_y', 'angle', 'radius', 'opacity', 'glow', 'pleft', 'pright'].forEach(k => {
            const ctrl = document.querySelector(`input[name="${key}_${k}"]`);
            if (ctrl) {
                ctrl.addEventListener('input', (e) => {
                    const valId = e.target.dataset.valId;
                    if (valId) {
                        const valEl = document.getElementById(valId);
                        if (valEl) {
                            if (valEl.tagName === 'INPUT') valEl.value = e.target.value;
                            else valEl.textContent = e.target.value;
                        }
                    }
                    const currentIdx = (typeof SVB_CURRENT_SCENE_INDEX !== 'undefined') ? SVB_CURRENT_SCENE_INDEX[key] : 0;
                    if (typeof SVB_SCENES_DATA !== 'undefined' && SVB_SCENES_DATA[key] && SVB_SCENES_DATA[key][currentIdx]) {
                        const val = parseFloat(e.target.value);
                        SVB_SCENES_DATA[key][currentIdx][k] = isNaN(val) ? 0 : val;
                    }
                    if (typeof svbUpdatePreviewTransform === 'function') svbUpdatePreviewTransform(key);
                    if (typeof svbDebugPrint === 'function') svbDebugPrint(key);
                });
            }
        });
    });

    // 2. Биндим загрузку файлов и Кроппер
    ['child1', 'child2', 'parent1', 'parent2'].forEach(key => {
        const input = document.querySelector(`input[name="photo_${key}"]`);
        if (!input) return;

        // Удаляем старые слушатели, чтобы не дублировать
        const newInput = input.cloneNode(true);
        input.parentNode.replaceChild(newInput, input);

        newInput.addEventListener('change', async function(e) {
            const files = e.target.files;
            if (!files || !files.length) return;
            if (newInput.dataset.processing === 'true') return;

            const rawFile = files[0];
            let file = rawFile;
            try {
                file = await svbNormalizeImageFile(rawFile);
            } catch (err) {
                console.error('HEIC normalize error', err);
                alert('Не вдалося конвертувати HEIC/HEIF у зображення. Спробуйте інший файл.');
                newInput.value = '';
                return;
            }

            if (file !== rawFile) {
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(file);
                newInput.files = dataTransfer.files;
            }

            svbCurrentInput = newInput;
            svbCurrentKey = key;

            const modal = document.getElementById('svb-crop-modal');
            const image = document.getElementById('svb-crop-target');
            const headerTitle = modal.querySelector('h3');

            const objectUrl = URL.createObjectURL(file);
            if (svbCurrentPreviewUrl) {
                URL.revokeObjectURL(svbCurrentPreviewUrl);
            }
            svbCurrentPreviewUrl = objectUrl;

            if (headerTitle) {
                headerTitle.textContent = key.includes('child') ? "Фото дитини (обрізка):" : "Фото дорослого (обрізка):";
            }

            image.src = objectUrl;
            modal.style.display = 'flex';
            modal.classList.add('active');

            if (svbCropper) {
                svbCropper.destroy();
                svbCropper = null;
            }

            // === ЛОГИКА ПРОПОРЦИЙ (Fixed) ===

            // 1. Получаем ID видео из hidden input, так надежнее
            let currentVidId = 'video1';
            const hiddenInput = document.getElementById('selected_video_id');
            if (hiddenInput && hiddenInput.value) {
                currentVidId = hiddenInput.value;
            } else if (typeof SVB_SELECTED_VIDEO_ID !== 'undefined') {
                currentVidId = SVB_SELECTED_VIDEO_ID;
            }

            // 2. Ищем пропорции
            let ratio = NaN; // По умолчанию Free
            let debugSource = "Default (NaN)";

            if (typeof SVB_ASPECT_RATIOS !== 'undefined') {
                let map = SVB_ASPECT_RATIOS[currentVidId];
                // Если для текущего видео нет настроек, пробуем video1
                if (!map) {
                    map = SVB_ASPECT_RATIOS['video1'];
                    debugSource = "Fallback to Video1";
                } else {
                    debugSource = "Config found for " + currentVidId;
                }

                if (map && map[key] !== undefined) {
                    ratio = map[key];
                    debugSource += ` -> Key ${key} found: ${ratio}`;
                } else {
                    debugSource += ` -> Key ${key} NOT found`;
                }
            }

            // Лог в консоль (яркий)
            console.log(`%c ✂️ CROPPER INIT: ${key} | Video: ${currentVidId} | Ratio: ${ratio} | Msg: ${debugSource}`, 'background: #222; color: #bada55; font-size: 12px; padding: 4px;');

            svbCropper = new Cropper(image, {
                viewMode: 1,
                dragMode: 'move',
                autoCropArea: 0.9,
                aspectRatio: ratio, // <--- ЗДЕСЬ ПРИМЕНЯЕТСЯ РАСЧЕТ
                restore: false,
                guides: true,
                center: true,
                highlight: false,
                cropBoxMovable: true,
                cropBoxResizable: true,
                toggleDragModeOnDblclick: false
            });
            newInput.value = '';
        });
    });

    // 3. Кнопка SAVE (обновленная)
    const cropModalSaveBtn = document.getElementById('svb-crop-save');
    if (cropModalSaveBtn) {
        const newBtn = cropModalSaveBtn.cloneNode(true);
        cropModalSaveBtn.parentNode.replaceChild(newBtn, cropModalSaveBtn);

        newBtn.addEventListener('click', () => {
            if (!svbCropper || !svbCurrentInput || !svbCurrentKey) return;

            // Генерируем с максимальным качеством, но БЕЗ width/height чтобы сохранить ratio
            const canvas = svbCropper.getCroppedCanvas({
                maxWidth: 2048,
                maxHeight: 2048,
                imageSmoothingEnabled: true,
                imageSmoothingQuality: 'high',
            });

            if (!canvas) return;

            canvas.toBlob((blob) => {
                if (!blob) return;
                const newFile = new File([blob], "cropped_image.webp", { type: "image/webp" });
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(newFile);

                svbCurrentInput.files = dataTransfer.files;
                svbCurrentInput.dataset.processing = 'true';
                svbCurrentInput.dataset.cropped = 'true';

                const imgEl = document.getElementById('img-' + svbCurrentKey);
                if (imgEl) {
                    const url = URL.createObjectURL(blob);
                    if (imgEl.src) URL.revokeObjectURL(imgEl.src);
                    imgEl.onload = () => {
                        if (typeof svbUpdatePreviewTransform === 'function') svbUpdatePreviewTransform(svbCurrentKey);
                        if (typeof svbDebugPrint === 'function') svbDebugPrint(svbCurrentKey);
                    };
                    imgEl.src = url;
                }

                if (typeof svbCloseCrop === 'function') svbCloseCrop();
                else {
                    document.getElementById('svb-crop-modal').style.display = 'none';
                    document.getElementById('svb-crop-modal').classList.remove('active');
                    if (svbCropper) svbCropper.destroy();
                }

                setTimeout(() => {
                    if (svbCurrentInput) svbCurrentInput.dataset.processing = 'false';
                }, 100);

            }, 'image/webp', 0.9);
        });
    }
  }
  function svbBindNumericControls() {
    $$('.svb-val-input').forEach(inp => {
        const rangeName = inp.dataset.rangeName;
        if (!rangeName) return;
        const range = document.querySelector(`input[name="${rangeName}"]`);
        if (!range) return;

        inp.addEventListener('input', () => {
            let v = parseFloat(inp.value);
            if (!Number.isFinite(v)) return;

            const min = parseFloat(range.min);
            const max = parseFloat(range.max);
            if (Number.isFinite(min)) v = Math.max(min, v);
            if (Number.isFinite(max)) v = Math.min(max, v);

            const step = parseFloat(range.step) || 1;
            v = Math.round(v / step) * step;

            range.value = v;
            range.dispatchEvent(new Event('input', { bubbles: true }));
        });
    });
}


const SVB_MODEL_W = 854;   
const SVB_MODEL_H = 480;   
const PROCESSED_SQUARE = (typeof SVB_PROCESSED_PHOTO_SIZE === 'number' && SVB_PROCESSED_PHOTO_SIZE > 0)
  ? SVB_PROCESSED_PHOTO_SIZE
  : 709;

const toEvenUp = v => { const n = Math.ceil(v); return (n & 1) ? (n + 1) : n; };
const clamp01 = v => Math.max(0, Math.min(1, v));

/* === ЗАМЕНИТЬ ПОЛНОСТЬЮ ФУНКЦИЮ svbCalculateGeomFromParams === */
function svbCalculateGeomFromParams(params, imgRatio = 1) {
    const p = params || {};
    // Если пропорция не передана, считаем 1.0
    const R = (typeof imgRatio === 'number' && imgRatio > 0) ? imgRatio : 1.0;

    const baseScalePct = p.scale !== undefined ? p.scale : 100;
    const strXPct      = p.scale_x !== undefined ? p.scale_x : 100;
    const strYPct      = p.scale_y !== undefined ? p.scale_y : 100;

    const skewXdeg  = p.skew    !== undefined ? p.skew : 0;
    const skewYdeg  = p.skew_y  !== undefined ? p.skew_y : 0;
    const angleDeg  = p.angle   !== undefined ? p.angle : 0;
    const radiusPx  = p.radius  !== undefined ? p.radius : 0;
    const xRaw      = p.x       !== undefined ? p.x : 0;
    const yRaw      = p.y       !== undefined ? p.y : 0;
    const glowVal   = p.glow    !== undefined ? p.glow : 0;

    // === FIX 1: Компенсация Padding от Glow ===
    // PHP добавляет отступы внутрь картинки. Чтобы визуальный размер фото остался прежним,
    // мы должны увеличить общий контейнер на коэффициент потери площади.
    let scaleMultiplier = 1.0;
    if (glowVal > 0) {
        // Формула из PHP: sigma = (glow/100)*30; padding = ceil(sigma*3 + 2)
        const sigma = (glowVal / 100.0) * 30.0;
        const paddingPx = Math.ceil((sigma * 3) + 2);
        
        // Базовый размер фото в обработчике PHP (стандарт Imagick ~1152px)
        const refW = 1152; 
        const contentW = refW - (paddingPx * 2);
        
        // Вычисляем, во сколько раз нужно увеличить картинку, чтобы контент вернулся к норме
        if (contentW > 0) {
            scaleMultiplier = refW / contentW;
        }
    }

    // Базовые коэффициенты масштаба
    const S  = Math.max(10, Math.min(200, baseScalePct)) / 100.0;
    const SX = Math.max(10, Math.min(300, strXPct))      / 100.0;
    const SY = Math.max(10, Math.min(200, strYPct))      / 100.0;

    // 1. Применяем мультипликатор к размерам контейнера
    const w_content = Math.max(2, Math.round(SVB_MODEL_W * S * SX * scaleMultiplier));
    
    // Высота считается от ширины с учетом пропорций
    const baseW_for_H = SVB_MODEL_W * S;
    const h_content   = Math.max(2, Math.round((baseW_for_H / R) * SY * scaleMultiplier));

    // 2. Координаты (PHP использует top-left, JS transform-origin center)
    const xBase = (xRaw / 1920) * SVB_MODEL_W;
    const yBase = (yRaw / 1080) * SVB_MODEL_H;

    // 3. Расчет визуального центра
    // Важно: scaleMultiplier увеличивает картинку во все стороны, центр остается на месте
    // относительно координат xRaw/yRaw, если мы считаем, что xRaw/yRaw указывают на верхний левый угол
    // ВИДИМОЙ части, а не паддинга. Но FFmpeg overlay позиционирует ВЕСЬ слой.
    
    // Чтобы синхронизировать, мы считаем центр "раздутого" контейнера
    const cx_model = xBase + (Math.round(SVB_MODEL_W * S * SX) / 2); // Центр без учета glow-раздутия
    const cy_model = yBase + (Math.round((baseW_for_H / R) * SY) / 2);

    // Возвращаем данные
    return {
        w_content, h_content,
        cx_norm: cx_model / SVB_MODEL_W,
        cy_norm: cy_model / SVB_MODEL_H,
        
        scale: baseScalePct,
        scaleX: strXPct,
        scaleY: strYPct,
        skew: skewXdeg,
        skewY: skewYdeg,
        angle: angleDeg,
        radius: radiusPx,
        glow: glowVal, // Передаем значение glow дальше
        pleft: p.pleft !== undefined ? p.pleft : 0,
        pright: p.pright !== undefined ? p.pright : 0,
        
        // Данные для JSON (Бэкенд)
        w_pred: w_content, 
        h_pred: h_content,
        video: { w: SVB_MODEL_W, h: SVB_MODEL_H },
        source_png: { square: 709 }
    };
}

/* === ЗАМЕНИТЬ ПОЛНОСТЬЮ ФУНКЦИЮ svbUpdatePreviewTransform === */
function svbUpdatePreviewTransform(key) {
    const img = document.getElementById('img-' + key);
    const preview = document.getElementById('svb-vid-preview-' + key);
    if (!img || !preview) return;

    const wrap = img.parentElement && img.parentElement.classList.contains('svb-ovbox')
        ? img.parentElement
        : null;
    if (!wrap) return;

    const geom = svbComputeOverlayGeom(key);
    const rect = preview.getBoundingClientRect();

    const kx = (rect.width || SVB_MODEL_W) / SVB_MODEL_W;
    const ky = (rect.height || SVB_MODEL_H) / SVB_MODEL_H;

    // --- Padding для Perspective (Str L/R) ---
    let padTop = 0;
    let extraH = 0;
    if (geom.pleft !== 0 || geom.pright !== 0) {
        padTop = Math.max(0, geom.pleft, geom.pright);
        const padBottom = Math.max(0, geom.pleft, geom.pright);
        if (padTop > 0 || padBottom > 0) extraH = padTop + padBottom;
    }

    // Размеры контейнера (уже с учетом раздутия от Glow из функции calculate)
    const w_canvas_orig = geom.w_content;
    const h_canvas_orig = geom.h_content;
    const h_canvas_padded = h_canvas_orig + extraH; 

    // Применяем размеры к картинке и обертке
    img.style.width  = Math.floor(w_canvas_orig * kx) + 'px';
    img.style.height = Math.floor(h_canvas_orig * ky) + 'px';
    img.style.left   = '0px'; 
    // Сдвигаем картинку вниз, если есть perspective padding
    img.style.top    = Math.floor(padTop * ky) + 'px';
    img.style.position = 'absolute';

    wrap.style.width  = Math.floor(w_canvas_orig * kx) + 'px';
    wrap.style.height = Math.floor(h_canvas_padded * ky) + 'px';
    
    // Позиционируем Wrap по центру координат
    const centerX = geom.cx_norm * rect.width;
    const centerY = geom.cy_norm * rect.height;
    
    wrap.style.left = (centerX - (parseFloat(wrap.style.width)/2)) + 'px';
    wrap.style.top  = (centerY - (parseFloat(wrap.style.height)/2)) + 'px';

    // === FIX 2: Порядок трансформаций CSS ===
    // Мы строим строку трансформации так, чтобы она соответствовала FFmpeg.
    // FFmpeg: Perspective -> Shear -> Rotate.
    // CSS применяется справа налево (или от вложенного к внешнему).
    // Поэтому порядок добавления в строку: rotate -> skew -> matrix.
    
    const t = [];

    // 1. Rotate (Внешнее вращение)
    if (geom.angle) t.push(`rotate(${geom.angle}deg)`);

    // 2. Skew (Наклон)
    if (geom.skew || geom.skewY) {
        t.push(`skew(${geom.skew}deg, ${geom.skewY}deg)`);
    }

    // 3. Perspective (Matrix3d)
    if (geom.pleft !== 0 || geom.pright !== 0) {
        const w = w_canvas_orig;
        const h_src = h_canvas_orig;
        
        // Логика проекции как в PHP
        const factorL = (h_src + 2.0 * geom.pleft) / h_src;
        const y0_calc = padTop * (1.0 - factorL) - geom.pleft;
        const y2_calc = factorL * (h_src + padTop) + padTop - geom.pleft;

        const factorR = (h_src + 2.0 * geom.pright) / h_src;
        const y1_calc = padTop * (1.0 - factorR) - geom.pright;
        const y3_calc = factorR * (h_src + padTop) + padTop - geom.pright;

        // Исходный прямоугольник (весь канвас с паддингом)
        const src = [[0, 0], [w, 0], [0, h_canvas_padded], [w, h_canvas_padded]];
        // Целевая трапеция
        const dst = [[0, y0_calc], [w, y1_calc], [0, y2_calc], [w, y3_calc]];

        const mat = svbSolveHomography(src, dst); // Использует твою функцию солвера
        t.push(svbArrToCssMatrix(mat));
    }

    wrap.style.transformOrigin = '50% 50%';
    wrap.style.transform = t.length ? t.join(' ') : 'none';
    
    // Сбрасываем трансформации на самой картинке (все делает wrap)
    img.style.transform = 'none';

    // Radius
    img.style.borderRadius = geom.radius > 0 ? Math.floor(geom.radius * kx) + 'px' : '0px';

    // === Визуальная имитация Glow Padding ===
    // Тут мы делаем так, чтобы картинка на фронте визуально уменьшилась внутри контейнера,
    // так же, как это делает PHP Imagick.
    if (geom.glow > 0) {
        const pxGlow = Math.round((geom.glow / 100) * 30);
        img.style.filter = `drop-shadow(0px 0px ${pxGlow}px rgba(255, 255, 255, 0.9))`;
        
        const sigma = (geom.glow / 100.0) * 30.0;
        let backendPaddingPx = Math.ceil(sigma * 3);
        if (backendPaddingPx < 2) backendPaddingPx = 2;
        
        // Приблизительный референсный размер для расчета % отступа
        const refW = 1152;
        const paddingPct = (backendPaddingPx / refW) * 100;
        
        img.style.boxSizing = 'border-box';
        
        // Учитываем искажение пропорций Scale X / Scale Y
        const sx = (geom.scaleX || 100);
        const sy = (geom.scaleY || 100);
        let distortion = (sx > 0) ? (sy / sx) : 1;
        
        // Добавляем padding внутрь картинки
        img.style.paddingLeft   = `${paddingPct}%`;
        img.style.paddingRight  = `${paddingPct}%`;
        img.style.paddingTop    = `${paddingPct * distortion}%`;
        img.style.paddingBottom = `${paddingPct * distortion}%`;
    } else {
        img.style.filter = 'none';
        img.style.padding = '0';
    }
}
/**
 * 2. Обновленная функция для Превью (читает из DOM).
 * Она просто собирает объект params и вызывает математическую функцию выше.
 */
function svbComputeOverlayGeom(key) {
    const num = (suffix, def = 0) => {
        const el = document.querySelector(`input[name="${key}_${suffix}"]`);
        const v = parseFloat(el?.value);
        return Number.isFinite(v) ? v : def;
    };

    const params = {
        scale:   num('scale', 100),
        scale_x: num('scale_x', 100), 
        scale_y: num('scale_y', 100),
        skew:    num('skew', 0),
        skew_y:  num('skew_y', 0),
        angle:   num('angle', 0),
        radius:  num('radius', 0),
        x:       num('x', 0),
        y:       num('y', 0),
        glow:    num('glow', 0),
        pleft:   num('pleft', 0),
        pright:  num('pright', 0)
    };

    // ВАЖНО: Читаем реальные пропорции картинки из DOM
    const img = document.getElementById('img-' + key);
    let ratio = 1;
    if (img && img.naturalWidth > 0 && img.naturalHeight > 0) {
        ratio = img.naturalWidth / img.naturalHeight;
    }

    return svbCalculateGeomFromParams(params, ratio);
}


function svbCollectOverlayData() {
    const data = {};
    const keys = ['child1', 'child2', 'parent1', 'parent2'];

    keys.forEach((key) => {
        // Зберігаємо поточні інпути в пам'ять перед збором
        if (typeof svbSaveInputsToScene === 'function') {
             svbSaveInputsToScene(key, SVB_CURRENT_SCENE_INDEX[key]);
        }

        if (!SVB_SCENES_DATA[key]) return;

        // Визначаємо співвідношення сторін
        let currentRatio = 1;
        const img = document.getElementById('img-' + key);
        if (img && img.naturalWidth > 0 && img.naturalHeight > 0) {
            currentRatio = img.naturalWidth / img.naturalHeight;
        }

        data[key] = SVB_SCENES_DATA[key].map((sceneParams, idx) => {
            // Передаємо ratio в калькулятор
            const geom = svbCalculateGeomFromParams(sceneParams, currentRatio);
            
            return {
                cx_norm:  geom.cx_norm,
                cy_norm:  geom.cy_norm,
                scale:    geom.scale,
                scale_x:  (geom.scaleX !== undefined) ? geom.scaleX : geom.scale, // Зберігаємо scale_x
                scaleY:   geom.scaleY,
                scale_y:  geom.scaleY,
                skew:     geom.skew,
                skewY:    geom.skewY,
                angle:    geom.angle,
                radius:   geom.radius,
                
    
                pleft:    geom.pleft,
                pright:   geom.pright,
                // ===================================================

                glow:     (sceneParams.glow !== undefined) ? sceneParams.glow : 0,
                opacity:  (sceneParams.opacity !== undefined) ? sceneParams.opacity : 100,
                
                img_ratio: currentRatio,
                
                x_norm:   geom.x_norm,
                y_norm:   geom.y_norm,
                w_pred:   geom.w_pred,
                h_pred:   geom.h_pred,
                video:    geom.video,
                source_png: geom.source_png
            };
        });
    });

    const field = document.getElementById('overlay_json');
    if (field) {
        field.value = JSON.stringify(data);
    }
    return data;
}

const svbNorm = s => (s||'').toString().toLowerCase().trim().replace(/[\s_\-’']/g,'');

let SVB_SELECTED = {};
function buildSoundMap(){
  const pull = (cat) => {
    // ВИПРАВЛЕННЯ: Використовуємо getAllNames() замість getNameOptionsByGender()
    let items = (cat === 'name') ? getAllNames() : (SVB_AUDIO[cat] || []);
    
    // Шукаємо селект. Для імен це може бути name_audio, name_audio_2, etc.
    // Але ця функція збирає основний масив для кроку 3.
    // Тут логіка спрощена для загального списку.
    const sel = document.querySelector(`select[name="${cat}_audio"]`);
    
    if (!sel) return null;
    const file = sel.value;
    const it = items.find(i => i.file === file);
    return it ? { file: it.file, url: it.url, label: it.label } : null;
  };

  SVB_SELECTED = {
    name:    pull('name'),
    age:     pull('age'),
    hobby:   pull('hobby'),
    praise:  pull('praise'),
    request: pull('request')
  };

  const box = document.getElementById('svb-result');
  if (box) {
    const rows = Object.entries(SVB_SELECTED)
      .filter(([k,v]) => !!v)
      .map(([k,v]) => `<div><b>${k}</b>: ${v.label} <small>(${v.file})</small></div>`)
      .join('');
  if (rows) { box.style.display='block'; box.innerHTML = `<div><b>Обрані озвучки:</b></div>${rows}`; }
  }
}

function svbSetLookupStatus(message, type = '') {
  const box = document.getElementById('svb-lookup-status');
  if (!box) return;
  box.textContent = message || '';
  box.classList.toggle('is-error', type === 'error');
  box.classList.toggle('is-success', type === 'success');
}

function svbHandleLookupSuccess(payload) {
  const url = payload.video_url || payload.download_url;
  if (!url) {
    svbSetLookupStatus('Готове відео не знайдено', 'error');
    return;
  }

  svbRecoveredFromLookup = true;
  svbLookupDownloadUrl = url;
  svbJobToken = null;
  svbGenerating = false;
  svbVideoURL = url;

  svbResetPreviewState();
  svbToggleVideoOverlay(false);
  svbRenderResultVideo(url);
  svbSetStep(3);

  const statusEl = document.getElementById('svb-status');
  if (statusEl) {
    statusEl.textContent = '✅ Відео знайдено';
  }
  const finishBtn = document.getElementById('svb-finish');
  if (finishBtn) {
    finishBtn.disabled = false;
  }
  const emailField = document.getElementById('svb-email');
  if (emailField && payload.customer_email) {
    emailField.value = payload.customer_email;
  }
  const hiddenVideo = document.getElementById('selected_video_id');
  if (hiddenVideo && payload.selected_video_id) {
    hiddenVideo.value = payload.selected_video_id;
  }

  svbUpdateState({
    step: 3,
    child_count: payload.child_count || svbCurrentChildCount,
    selected_video_id: payload.selected_video_id || SVB_SELECTED_VIDEO_ID,
    lookup_order_id: payload.order_id || null,
  });
}

async function svbLookupExistingVideo() {
  if (svbLookupInFlight) return;

  const emailInput = document.getElementById('svb-lookup-email');
  const orderInput = document.getElementById('svb-lookup-order');
  const submitBtn = document.getElementById('svb-lookup-submit');

  const email = emailInput ? emailInput.value.trim() : '';
  const orderRaw = orderInput ? orderInput.value.trim() : '';
  const orderId = orderRaw ? parseInt(orderRaw, 10) : 0;

  if (!email && !orderId) {
    svbSetLookupStatus('Вкажіть email або номер замовлення', 'error');
    return;
  }

  svbLookupInFlight = true;
  svbSetLookupStatus('Шукаємо відео…');
  if (submitBtn) {
    submitBtn.disabled = true;
    submitBtn.classList.add('is-loading');
  }

  try {
    const fd = new FormData();
    fd.append('action', 'svb_find_video');
    fd.append('_svb_nonce', SVB_AJAX.nonce);
    if (email) fd.append('email', email);
    if (orderId) fd.append('order_id', orderId);

    const res = await fetch(SVB_AJAX.url, { method: 'POST', body: fd, credentials: 'same-origin' });
    if (!res.ok) {
      throw new Error('HTTP ' + res.status);
    }
    const json = await res.json();
    if (!json.success) {
      throw new Error(json.data || 'Не знайдено');
    }

    svbHandleLookupSuccess(json.data || {});
    svbSetLookupStatus('✅ Знайшли готове відео', 'success');
  } catch (err) {
    const msg = err && err.message ? err.message : 'Не знайдено';
    svbSetLookupStatus(msg, 'error');
  } finally {
    svbLookupInFlight = false;
    if (submitBtn) {
      submitBtn.disabled = false;
      submitBtn.classList.remove('is-loading');
    }
  }
}

async function svbRecoverProbe(email, name) {
  const { masked: emailMasked, normalized: emailNormalized } = svbMaskEmail(email);
  const noncePresent = !!(SVB_AJAX && SVB_AJAX.nonce);
  const willCallRecover = !!(emailNormalized && noncePresent);

  svbLogRecover('Step1 submit', {
    enteredEmailMasked: emailMasked,
    emailNormalized,
    noncePresent,
    willCallRecover,
  });

  if (!willCallRecover) {
    return { action: 'continue', reason: 'missing_email_or_nonce', usedRecover: false };
  }

  const fd = new FormData();
  fd.append('action', 'svb_order_recover');
  fd.append('_svb_nonce', SVB_AJAX.nonce);
  fd.append('email', emailNormalized);
  if (name) {
    fd.append('name', name);
  }

  const recoverPayload = {
    email_masked: emailMasked,
    email_normalized: emailNormalized,
  };
  svbLogRecover('request', recoverPayload);
  console.log('[SVB RECOVER] ajax request', Object.assign({ action: 'svb_order_recover', hasNonce: noncePresent }, recoverPayload));

  try {
    const res = await fetch(SVB_AJAX.url, { method: 'POST', body: fd, credentials: 'same-origin' });
    svbLogRecover('response status', {
      status: res.status,
      ok: res.ok,
      redirected: res.redirected,
      url: res.url,
    });

    if (!res.ok) {
      return { action: 'continue', reason: 'http_error', status: res.status, usedRecover: true };
    }

    const data = await res.json();
    svbLogRecover('response body', data);

    if (!data || typeof data !== 'object') {
      return { action: 'continue', reason: 'bad_json', usedRecover: true };
    }

    if (!data.success) {
      return { action: 'continue', reason: data.data || 'recover_denied', usedRecover: true, raw: data };
    }

    const payload = data.data || {};
    const debug = payload.debug || null;
    const orderId = payload.order && payload.order.order_id ? payload.order.order_id : null;
    const resumeMasked = payload.order && payload.order.resume_url ? svbMaskUrlToken(payload.order.resume_url) : '';

    svbLogRecover('decision', {
      action: payload.action,
      found: payload.found,
      order_id: orderId,
      resume_url: resumeMasked,
      reason: payload.reason || (debug && debug.reason) || '',
    });

    console.log('[SVB RECOVER] response', {
      action: payload.action,
      found: payload.found,
      reasonCode: payload.reason || (debug && debug.reason) || '',
      order_id: orderId,
    });

    return Object.assign({ usedRecover: true }, payload);
  } catch (err) {
    svbLogRecover('recover failed', err);
    return { action: 'continue', reason: 'fetch_error', usedRecover: true };
  }
}

async function svbOrderRecoverRequest(email, name) {
  return svbRecoverProbe(email, name);
}

function svbCloseRecoverModal(e, force = false) {
  const modal = document.getElementById('svb-recover-modal');
  if (!modal) return;
  if (force || e.target === modal) {
    modal.classList.remove('active');
    modal.style.display = 'none';
  }
}

function svbShowRecoverModal(order) {
  const modal = document.getElementById('svb-recover-modal');
  if (!modal || !order) {
    svbSetStep(2);
    return;
  }

  const orderIdNode = modal.querySelector('[data-recover-order-id]');
  const videoStateNode = modal.querySelector('[data-recover-video-state]');
  const regenNode = modal.querySelector('[data-recover-regen]');
  const openBtn = modal.querySelector('#svb-recover-open');

  if (orderIdNode) orderIdNode.textContent = order.order_id || '';
  if (videoStateNode) videoStateNode.textContent = order.has_video ? 'готове' : 'видалено';
  if (regenNode) regenNode.textContent = order.can_regen ? 'Можна перегенерувати без нової оплати.' : 'Потрібна нова оплата для змінених параметрів.';
  if (openBtn) openBtn.dataset.resumeUrl = order.resume_url || '';

  modal.style.display = 'flex';
  modal.classList.add('active');
}

async function svbHandleStep1Next(e) {
  e.preventDefault();
  const saved = svbLoadState();
  if (saved && saved.order_id && saved.public_token) {
    svbSetStep(2);
    return;
  }

  const emailInput = document.querySelector('input[name="customer_email_step1"]');
  const nameInput = document.querySelector('input[name="customer_name"]');
  const email = emailInput && emailInput.value ? emailInput.value.trim() : '';
  const name = nameInput && nameInput.value ? nameInput.value.trim() : '';
  const { masked: emailMasked, normalized: emailNormalized } = svbMaskEmail(email);

  console.log('[SVB RECOVER] click', {
    email_present: !!email,
    email_masked: emailMasked,
    orderKnown: !!(saved && saved.order_id),
  });

  if (!email) {
    svbSetStep(2);
    return;
  }

  try {
    const resp = await svbRecoverProbe(emailNormalized, name);
    console.log('[SVB RECOVER] response', {
      action: resp.action,
      reason: resp.reason,
      order_id: resp.order && resp.order.order_id ? resp.order.order_id : null,
    });
    const reason = resp.reason || 'no_reason';

    if (resp.action === 'popup' && resp.order) {
      svbLogRecover('popup decision', { order_id: resp.order.order_id, reason });
      svbShowRecoverModal(resp.order);
      return;
    }

    if (resp.action === 'info_pending') {
      alert('Оплата ще не підтверджена. Очікуємо підтвердження від банку.');
    }

    if (resp.action === 'email_sent') {
      svbLogRecover('email_sent decision', { reason });
      alert('Якщо замовлення існує — ми надіслали посилання на email.');
    } else {
      svbLogRecover('continue decision', { reason });
      console.log('[SVB RECOVER] popup not shown', { action: resp.action, reasonCode: reason });
      if (resp.debug && resp.debug.session_cookie_present === false) {
        alert('Якщо замовлення існує — ми надіслали посилання на email.');
      }
    }
  } catch (err) {
    svbLogRecover('recover failed', err);
  }

  svbSetStep(2);
}

async function svbHandleResumeFromUrl(params) {
  if (!params || (!params.has('svb_resume_order') && !params.has('svb_order') && !params.has('svb_token') && !params.has('svb_payment_return'))) return false;

  const orderId = parseInt(params.get('order_id') || '0', 10);
  const token = params.get('token') || params.get('svb_order') || params.get('svb_token') || '';
  if (!token) return false;

  const flagKey = `svb_resume_redirect_done:${orderId}`;
  const alreadyHandled = sessionStorage.getItem(flagKey) === '1';
  if (alreadyHandled) {
    const cleanUrl = window.location.protocol + '//' + window.location.host + window.location.pathname;
    window.history.replaceState({}, document.title, cleanUrl);
    return true;
  }

  svbEnsureStateStructure();
  if (orderId) {
    svbUpdateState({ order_id: orderId, public_token: token });
    svbStoreLastOrderToken(orderId, token);
  }
  try {
    document.cookie = `svb_public_token=${token}; path=/; max-age=${30 * 24 * 60 * 60}; SameSite=Lax`;
  } catch (e) {}

  try {
    const info = await svbOrderResumeInfoRequest(orderId, token);
    const resolvedOrderId = (info && info.order_id) ? info.order_id : orderId;
    if (resolvedOrderId) {
      svbUpdateState({ order_id: resolvedOrderId, public_token: token });
      svbStoreLastOrderToken(resolvedOrderId, token);
    }
      console.log('[SVB RETURN]', {
        start: true,
        chosen_order_id: resolvedOrderId,
        state_ready: !!svbState,
        is_payment_return: false,
        resume_source: 'url',
      });

    if (info && info.form_data) {
      try {
        const current = svbEnsureStateStructure();
        const restored = Object.assign({}, current, {
          child_count: info.form_data.child_count || current.child_count || 1,
          selected_video_id: info.form_data.selected_video_id || current.selected_video_id || 'video1',
          formData: Object.assign({}, info.form_data)
        });
        svbUpdateState(restored);
        if (typeof svbRestoreStep2State === 'function') {
          svbRestoreStep2State();
        }
      } catch (restoreErr) {
        console.log('[SVB RESUME][ERROR]', { message: restoreErr.message, stack: restoreErr.stack });
      }
    }

    if (info && info.has_video && info.download_url) {
      svbSetStep(3);
      await svbHandlePaidResume(resolvedOrderId, token, info);
    } else if (info && (info.can_regen || info.found)) {
      svbSetStep(3);
      await svbHandlePaidResume(resolvedOrderId, token, info);
    } else {
      svbSetStep(2);
      if (typeof svbRenderUI === 'function') {
        svbRenderUI();
        svbRestoreStep2State();
      }
    }
  } catch (resumeErr) {
    console.log('[SVB RESUME][ERROR]', { message: resumeErr.message, stack: resumeErr.stack });
  }

  sessionStorage.setItem(flagKey, '1');
  const cleanUrl = window.location.protocol + '//' + window.location.host + window.location.pathname;
  window.history.replaceState({}, document.title, cleanUrl);
  return true;
}

async function svbAutoResumeFromLocal(opts = {}) {
  const isPaymentReturn = !!opts.isPaymentReturn;
  const domReady = !!document.querySelector('.svb-step');
  const stateReady = !!svbLoadState();
  console.log('[SVB RESUME][INIT]', { started: true, isPaymentReturn, stateReady, domReady });

  if (isPaymentReturn) {
    console.log('[SVB RESUME][CHOOSE]', { chosen: 'none', order_id: null });
    return;
  }

  if (!domReady || !stateReady) {
    setTimeout(() => svbAutoResumeFromLocal(opts), 300);
    return;
  }

  svbEnsureStateStructure();

  const lastAttempt = svbLoadLastPaymentAttempt();
  let stored = svbLoadLastPaidOrder();
  const storedOrderToken = svbLoadLastOrderToken();

  if (lastAttempt && (!stored || (lastAttempt.saved_at > (stored.saved_at || 0)))) {
    stored = {
      order_id: lastAttempt.order_id,
      token: lastAttempt.public_token || '',
      saved_at: lastAttempt.saved_at || Date.now(),
    };
    console.log('[SVB RESUME][CHOOSE]', { chosen: 'lastPaymentAttempt', order_id: stored.order_id });
  }

  if ((!stored || !stored.order_id || !stored.token) && storedOrderToken && storedOrderToken.order_id) {
    stored = {
      order_id: storedOrderToken.order_id,
      token: storedOrderToken.token,
      saved_at: storedOrderToken.saved_at || Date.now(),
    };
    console.log('[SVB RESUME][CHOOSE]', { chosen: 'lastOrderToken', order_id: stored.order_id });
  }

  if (!stored || !stored.order_id || !stored.token) {
    console.log('[SVB RESUME][CHOOSE]', { chosen: 'none', order_id: null });
    return;
  }

  console.log('[SVB RESUME][CHOOSE]', { chosen: 'lastPaidOrder', order_id: stored.order_id });

  try {
    const resp = await svbOrderResumeInfoRequest(stored.order_id, stored.token);
    console.log('[SVB RESUME] response', {
      found: resp.found,
      reason: resp.reason,
      order_id: resp.order_id || null,
      can_regen: resp.can_regen,
    });

    if (resp && resp.found) {
      svbUpdateState({ order_id: resp.order_id, public_token: resp.public_token });
      svbShowRecoverModal({
        order_id: resp.order_id,
        has_video: resp.has_video,
        can_regen: resp.can_regen,
        resume_url: resp.resume_url,
      });
      return;
    }

    console.log('[SVB RESUME] popup not shown', { reasonCode: resp && resp.reason ? resp.reason : 'not_found' });
  } catch (e) {
    console.log('[SVB RESUME][ERROR]', { message: e.message, stack: e.stack });
  }
}

$('#svb-next-1').addEventListener('click', svbHandleStep1Next);
$('#svb-back-2').addEventListener('click', ()=> svbSetStep(1));

const SVB_PAYMENT_STORAGE = {
  invoice: 'svb_payment_invoice',
  step: 'svb_step2_state'
};

const SVB_PAYMENT_SESSION = {
  order: 'svb_last_order_id',
  invoice: 'svb_last_invoice_masked',
  fingerprint: 'svb_last_fingerprint_prefix'
};

const SVB_RESUME_STORAGE = 'svb_last_paid_order';
const SVB_LAST_PAYMENT_ATTEMPT = 'svb_last_payment_attempt';
const SVB_LAST_ORDER_TOKEN = 'svb_last_order_token';

function svbSaveLastPaymentAttempt(meta = {}) {
  if (!meta.order_id) return;
  const payload = {
    order_id: meta.order_id,
    public_token: meta.public_token || '',
    invoiceId: meta.invoiceId || '',
    invoice_masked: meta.invoiceId ? svbMaskInvoiceId(meta.invoiceId) : '',
    pageUrl: meta.pageUrl || '',
    saved_at: Date.now(),
    fingerprint_prefix: meta.fingerprint_prefix || '',
  };

  try { localStorage.setItem(SVB_LAST_PAYMENT_ATTEMPT, JSON.stringify(payload)); } catch (e) {}
  console.log('[SVB PAY][RETURN]', {
    hasLastPaymentAttempt: true,
    order_id: payload.order_id,
    invoice_masked: payload.invoice_masked,
    fingerprint_prefix: payload.fingerprint_prefix,
    step: (svbLoadState().step) || null,
  });
}

function svbStoreLastOrderToken(orderId, token) {
  if (!orderId || !token) return;
  const payload = {
    order_id: orderId,
    token,
    saved_at: Date.now(),
  };
  try { localStorage.setItem(SVB_LAST_ORDER_TOKEN, JSON.stringify(payload)); } catch (e) {}
}

function svbLoadLastOrderToken() {
  try {
    const raw = localStorage.getItem(SVB_LAST_ORDER_TOKEN);
    if (!raw) return null;
    const parsed = JSON.parse(raw);
    if (parsed && parsed.order_id && parsed.token) return parsed;
  } catch (e) {}
  return null;
}

function svbClearLastOrderToken() {
  try { localStorage.removeItem(SVB_LAST_ORDER_TOKEN); } catch (e) {}
}

function svbLoadLastPaymentAttempt() {
  try {
    const raw = localStorage.getItem(SVB_LAST_PAYMENT_ATTEMPT);
    if (!raw) return null;
    const parsed = JSON.parse(raw);
    if (parsed && parsed.order_id) return parsed;
  } catch (e) {}
  return null;
}

function svbClearLastPaymentAttempt() {
  try { localStorage.removeItem(SVB_LAST_PAYMENT_ATTEMPT); } catch (e) {}
}

function svbStorePaymentDebugMeta(orderId, invoiceId, fingerprint) {
  try {
    if (orderId) {
      sessionStorage.setItem(SVB_PAYMENT_SESSION.order, String(orderId));
    }
    if (invoiceId) {
      sessionStorage.setItem(SVB_PAYMENT_SESSION.invoice, svbMaskInvoiceId(invoiceId));
    }
    if (fingerprint) {
      sessionStorage.setItem(SVB_PAYMENT_SESSION.fingerprint, String(fingerprint).slice(0, 12));
    }
  } catch (e) {}

  console.log('[SVB PAY][STORE]', {
    order_id: orderId || null,
    invoice_masked: invoiceId ? svbMaskInvoiceId(invoiceId) : '',
    fingerprint_prefix: fingerprint ? String(fingerprint).slice(0, 8) : '',
  });
}

function svbLoadPaymentDebugMeta() {
  try {
    return {
      order_id: parseInt(sessionStorage.getItem(SVB_PAYMENT_SESSION.order) || '0', 10) || 0,
      invoice_masked: sessionStorage.getItem(SVB_PAYMENT_SESSION.invoice) || '',
      fingerprint_prefix: sessionStorage.getItem(SVB_PAYMENT_SESSION.fingerprint) || '',
    };
  } catch (e) {
    return { order_id: 0, invoice_masked: '', fingerprint_prefix: '' };
  }
}

function svbSaveLastPaidOrder(orderId, token, paidFingerprint) {
  if (!orderId || !token) return;
  const payload = {
    order_id: orderId,
    token: token,
    token_masked: svbMaskToken(token),
    paid_fingerprint_prefix: paidFingerprint ? String(paidFingerprint).slice(0, 8) : '',
    saved_at: Date.now()
  };

  try {
    localStorage.setItem(SVB_RESUME_STORAGE, JSON.stringify(payload));
  } catch (e) {}

  console.log('[SVB RESUME] saved', { order_id: orderId, token_masked: payload.token_masked, paid_fp: payload.paid_fingerprint_prefix });
}

function svbLoadLastPaidOrder() {
  try {
    const raw = localStorage.getItem(SVB_RESUME_STORAGE);
    if (!raw) return null;
    const parsed = JSON.parse(raw);
    if (parsed && parsed.order_id && parsed.token) return parsed;
  } catch (e) {}
  return null;
}

function svbClearLastPaidOrder() {
  try { localStorage.removeItem(SVB_RESUME_STORAGE); } catch (e) {}
}

function svbGetSelectedChildCount() {
  const checked = document.querySelector('input[name="child_count"]:checked');
  return checked ? parseInt(checked.value, 10) || 1 : 1;
}

function svbPersistStep2State() {
  const form = document.getElementById('svb-form');
  if (!form) return;

  const payload = {};
  form.querySelectorAll('input, select, textarea').forEach(el => {
    if (!el.name || el.type === 'file') return;
    if (el.type === 'radio' || el.type === 'checkbox') {
      if (el.checked) payload[el.name] = el.value;
      return;
    }
    payload[el.name] = el.value;
  });

  payload.child_count = svbGetSelectedChildCount();
  payload.selected_video_id = SVB_SELECTED_VIDEO_ID;

  svbUpdateState({
    step: 2,
    child_count: payload.child_count,
    selected_video_id: SVB_SELECTED_VIDEO_ID,
    formData: payload
  });

  try {
    localStorage.setItem(SVB_PAYMENT_STORAGE.step, JSON.stringify(payload));
  } catch (e) {
    console.warn('Cannot persist step2 state', e);
  }
}

function svbRestoreStep2State() {
  const savedState = svbLoadState();
  let payload = savedState.formData;

  if (!payload) {
    const raw = localStorage.getItem(SVB_PAYMENT_STORAGE.step);
    if (raw) {
      try {
        payload = JSON.parse(raw);
        svbUpdateState({ formData: payload });
      } catch (e) {
        payload = null;
      }
    }
  }

  if (!payload || typeof payload !== 'object') return;

  Object.entries(payload).forEach(([name, value]) => {
    const nodes = document.querySelectorAll(`[name="${name}"]`);
    nodes.forEach(el => {
      if (el.type === 'radio' || el.type === 'checkbox') {
        el.checked = (el.value === String(value));
      } else {
        el.value = value;
      }
    });
  });

  if (payload.child_count) {
    const radio = document.querySelector(`input[name="child_count"][value="${payload.child_count}"]`);
    if (radio) {
      radio.checked = true;
      svbCurrentChildCount = parseInt(payload.child_count, 10) || svbCurrentChildCount;
    }
  }

  if (payload.selected_video_id || savedState.selected_video_id) {
    const chosen = payload.selected_video_id || savedState.selected_video_id;
    SVB_SELECTED_VIDEO_ID = chosen;
    const hInput = document.getElementById('selected_video_id');
    if (hInput) hInput.value = chosen;
  }

  svbRenderUI();

  const checked = document.querySelector('input[name="child_count"]:checked');
  if (checked) {
    checked.dispatchEvent(new Event('change', { bubbles: true }));
  }
}

function svbStoreInvoiceId(invoiceId) {
  if (!invoiceId) return;
  try { localStorage.setItem(SVB_PAYMENT_STORAGE.invoice, invoiceId); } catch(e) {}
}

function svbGetStoredInvoiceId() {
  try { return localStorage.getItem(SVB_PAYMENT_STORAGE.invoice) || ''; } catch(e) { return ''; }
}

function svbClearInvoiceId() {
  try { localStorage.removeItem(SVB_PAYMENT_STORAGE.invoice); } catch(e) {}
}

function svbIsPaymentDisabledByAdmin(logMissing = false) {
  const toggle = document.querySelector('#svb_payment_toggle');
  if (!toggle) {
    if (logMissing) {
      svbError('[SVB PAY] payment toggle not found in DOM, defaulting to payRequired=true');
    }
    return false;
  }
  return !toggle.checked;
}

function svbHidePaymentError() {
  const box = document.getElementById('svb-payment-error');
  if (!box) return;
  box.style.display = 'none';
}

const SVB_PENDING = {
  context: null,
  timer: null,
};

function svbHidePendingNotice() {
  const box = document.getElementById('svb-payment-pending');
  if (box) {
    box.style.display = 'none';
  }
  if (SVB_PENDING.timer) {
    clearInterval(SVB_PENDING.timer);
    SVB_PENDING.timer = null;
  }
  SVB_PENDING.context = null;
}

function svbShowPendingNotice(message, context = {}) {
  const box = document.getElementById('svb-payment-pending');
  const text = document.getElementById('svb-payment-pending-text');
  if (text && message) {
    text.textContent = message;
  }
  if (box) {
    box.style.display = 'block';
  }
  SVB_PENDING.context = Object.assign({}, context, { deadline: Date.now() + 60000 });
  console.groupCollapsed('[SVB PAY][PENDING] context');
  console.log({
    order_id: context.order_id || null,
    invoice_masked: context.invoice_id ? svbMaskInvoiceId(context.invoice_id) : '',
    page_url_masked: context.pageUrl ? svbMaskUrlToken(context.pageUrl) : '',
    has_page_url: !!context.pageUrl,
    fingerprint_current: context.fingerprint_current || '',
    invoice_fingerprint: context.invoice_fingerprint || '',
  });
  console.groupEnd();
}

async function svbPendingOpenInvoice() {
  const ctx = SVB_PENDING.context || {};
  if (ctx.pageUrl) {
    window.location = ctx.pageUrl;
    return;
  }

  await svbPendingSyncStatus('open_click');
  if (SVB_PENDING.context && SVB_PENDING.context.pageUrl) {
    window.location = SVB_PENDING.context.pageUrl;
    return;
  }

  svbShowPaymentError('Invoice pending but pageUrl missing.');
}

async function svbPendingNewInvoice() {
  const ctx = SVB_PENDING.context || {};
  const saved = svbLoadState();
  const orderCtx = {
    order_id: ctx.order_id || (saved && saved.order_id ? saved.order_id : null),
    public_token: ctx.token || (saved && saved.public_token ? saved.public_token : null),
  };
  const childCount = ctx.child_count || svbGetSelectedChildCount();

  try {
    if (orderCtx.order_id && ctx.invoice_id) {
      await svbInvalidateInvoice(orderCtx.order_id, orderCtx.public_token || '');
    }
    const invoice = await svbCreateInvoice(childCount, orderCtx, true);
    if (invoice && invoice.invoiceId) {
      svbStoreInvoiceId(invoice.invoiceId);
      svbStorePaymentDebugMeta(orderCtx.order_id, invoice.invoiceId, ctx.fingerprint_current || '');
      svbSaveLastPaymentAttempt({
        order_id: orderCtx.order_id,
        public_token: orderCtx.public_token,
        invoiceId: invoice.invoiceId,
        pageUrl: invoice.pageUrl || '',
        fingerprint_prefix: (ctx.fingerprint_current || '').toString().slice(0, 8),
      });
    }
    if (invoice && invoice.pageUrl) {
      window.location = invoice.pageUrl;
      return;
    }
    svbShowPaymentError('Не вдалося створити новий інвойс. Спробуйте ще раз.');
  } catch (err) {
    console.error('[SVB PAY][PENDING] new invoice failed', err);
    svbShowPaymentError(err.message || 'Оплата тимчасово недоступна.');
  }
}

async function svbPendingAlreadyPaid() {
  await svbPendingSyncStatus('button_click');
}

async function svbPendingSyncStatus(forceLogLabel = 'manual') {
  if (!SVB_PENDING.context) return;
  const { order_id: orderId, token } = SVB_PENDING.context;
  if (!orderId) return;

  try {
    const state = await svbSyncPaymentStatus(orderId, token || '');
    console.log('[SVB PAY][SYNC]', {
      label: forceLogLabel,
      decision: state.decision,
      payment_status: state.payment_status,
      modifiedDate: state.modifiedDate,
      invoice_page_url_masked: state.invoice_page_url_masked,
      has_page_url: state.has_page_url,
      fingerprint_current: state.fingerprint_current,
      paid_fingerprint: state.paid_fingerprint,
    });

    if (state.pageUrl && !SVB_PENDING.context.pageUrl) {
      SVB_PENDING.context.pageUrl = state.pageUrl;
    }

    if (state.decision === 'success' || state.decision === 'paid') {
      svbHandlePaymentDecision(orderId, state, token || '', true);
      return true;
    }

    if (['failed', 'failure', 'no_invoice', 'mono_error'].includes(state.decision)) {
      svbHidePendingNotice();
      svbShowPaymentError('Оплата не підтверджена. Спробуйте ще раз.');
      return false;
    }

    return state.decision === 'pending';
  } catch (err) {
    console.error('[SVB PAY][SYNC] pending error', err);
    return false;
  }
}

function svbStartPendingPoll() {
  if (!SVB_PENDING.context) return;
  if (SVB_PENDING.timer) {
    clearInterval(SVB_PENDING.timer);
  }

  SVB_PENDING.timer = setInterval(async () => {
    if (!SVB_PENDING.context) {
      clearInterval(SVB_PENDING.timer);
      SVB_PENDING.timer = null;
      return;
    }

    if (SVB_PENDING.context.deadline && Date.now() > SVB_PENDING.context.deadline) {
      clearInterval(SVB_PENDING.timer);
      SVB_PENDING.timer = null;
      console.warn('[SVB PAY][PENDING] deadline reached, suggesting new invoice');
      const text = document.getElementById('svb-payment-pending-text');
      if (text) {
        text.textContent = 'Оплата все ще очікується. Можете оновити сторінку оплати або створити новий інвойс.';
      }
      return;
    }

    const stillPending = await svbPendingSyncStatus('auto');
    if (!stillPending) {
      clearInterval(SVB_PENDING.timer);
      SVB_PENDING.timer = null;
    }
  }, 4000);
}

function svbShowPaymentError(message) {
  const box = document.getElementById('svb-payment-error');
  if (!box) {
    alert(message);
    return;
  }

  const text = box.querySelector('.svb-payment-error__text');
  if (text) {
    text.textContent = message;
  }
  box.style.display = 'block';
}

function svbAugmentReturnUrlWithOrder(url, orderContext = {}) {
  try {
    const target = new URL(url, window.location.origin);
    if (orderContext && orderContext.order_id) {
      target.searchParams.set('order_id', orderContext.order_id);
    }
    if (orderContext && orderContext.public_token) {
      target.searchParams.set('token', orderContext.public_token);
      target.searchParams.set('svb_order', orderContext.public_token);
    }
    target.searchParams.set('svb_payment_return', '1');
    return target.toString();
  } catch (e) {
    return url;
  }
}

async function svbCreateInvoice(childCount, orderContext = {}, forceNew = false) {
  const fd = new FormData();
  fd.append('action', 'svb_monobank_create_invoice');
  fd.append('_svb_nonce', SVB_AJAX.nonce);
  fd.append('child_count', childCount);
  const safeReturnUrl = (SVB_PAYMENT.return_url || window.location.href || '').split('#')[0];
  const enhancedReturnUrl = svbAugmentReturnUrlWithOrder(safeReturnUrl, orderContext);
  fd.append('return_url', enhancedReturnUrl);

  if (orderContext && orderContext.order_id) {
    fd.append('order_id', orderContext.order_id);
  }
  if (orderContext && orderContext.public_token) {
    fd.append('token', orderContext.public_token);
  }

  if (forceNew) {
    fd.append('force_new', '1');
  }

  if (SVB_PAYMENT.is_admin && svbIsPaymentDisabledByAdmin()) {
    fd.append('payment_disabled', '1');
  }

  svbLog('[SVB PAY] Preparing invoice request', {
    action: 'svb_monobank_create_invoice',
    hasNonce: !!SVB_AJAX.nonce,
    payload: {
      child_count: childCount,
      return_url: enhancedReturnUrl,
      payment_disabled: SVB_PAYMENT.is_admin && svbIsPaymentDisabledByAdmin() ? '1' : '0',
      order_id: orderContext && orderContext.order_id ? orderContext.order_id : null,
      token: orderContext && orderContext.public_token ? svbMaskToken(orderContext.public_token) : null,
      force_new: !!forceNew,
    }
  });

  const res = await fetch(SVB_AJAX.url, { method: 'POST', body: fd });
  svbLog('[SVB PAY] Invoice fetch response', { ok: res.ok, status: res.status });
  if (!res.ok) {
    svbError('[SVB PAY] Invoice request failed before JSON', { status: res.status });
    throw new Error('Помилка серверу під час ініціалізації оплати');
  }

  const data = await res.json();
  svbLog('[SVB PAY] Invoice response body', data);
  if (!data.success) {
    svbError('[SVB PAY] Invoice creation returned error', data.data || data);
    throw new Error(data.data || 'Оплата недоступна');
  }

  return data.data;
}

async function svbStartPaymentRedirect(orderId, token, gateContext = {}, childCount = 1) {
  console.log(`[SVB PAY] starting payment for order_id=${orderId || 'n/a'}`, { token_masked: svbMaskToken(token || '') });
  if (orderId && token) {
    svbStoreLastOrderToken(orderId, token);
  }

  const status = (gateContext && gateContext.payment_status ? gateContext.payment_status : '').toString().toLowerCase();
  const decision = (gateContext && gateContext.decision ? gateContext.decision : '').toString().toLowerCase();
  const forceFreshInvoice = ['failure', 'failed', 'no_invoice', 'mono_error', 'not_paid', 'unpaid', 'expired', 'canceled', 'cancelled']
    .includes(status) || ['failed', 'not_paid'].includes(decision);

  const paymentUrl = forceFreshInvoice ? '' : (gateContext.payment_url || gateContext.invoice_page_url || gateContext.pageUrl || '');
  if (paymentUrl) {
    console.log('[SVB PAY] payment_url=', svbMaskUrlToken(paymentUrl));
    console.log('[SVB PAY] redirecting…');
    window.location = paymentUrl;
    return;
  }

  if (forceFreshInvoice) {
    console.warn('[SVB PAY] forcing new invoice because gate returned failed/unpaid status', { status, decision });
  }

  const invoice = await svbCreateInvoice(childCount, { order_id: orderId, public_token: token }, true);
  if (invoice && invoice.invoiceId) {
    svbStoreInvoiceId(invoice.invoiceId);
    svbStorePaymentDebugMeta(orderId || 0, invoice.invoiceId, gateContext.fingerprint_current || '');
    svbSaveLastPaymentAttempt({
      order_id: orderId || 0,
      public_token: token || '',
      invoiceId: invoice.invoiceId,
      pageUrl: invoice.pageUrl || '',
      fingerprint_prefix: (gateContext.fingerprint_current || '').toString().slice(0, 8),
    });
  }

  if (invoice && invoice.pageUrl) {
    console.log('[SVB PAY] payment_url=', svbMaskUrlToken(invoice.pageUrl));
    console.log('[SVB PAY] redirecting…');
    window.location = invoice.pageUrl;
    return;
  }

  svbShowPaymentError('Не вдалося отримати лінк на оплату. Спробуйте ще раз.');
}

async function svbInvalidateInvoice(orderId, token) {
  const fd = new FormData();
  fd.append('action', 'svb_monobank_invalidate_invoice');
  fd.append('_svb_nonce', SVB_AJAX.nonce);
  fd.append('order_id', orderId);
  if (token) {
    fd.append('token', token);
  }

  svbLog('INVALIDATE request', {
    order_id: orderId,
    token_prefix: token ? svbMaskToken(token) : '',
  });

  const res = await fetch(SVB_AJAX.url, { method: 'POST', body: fd });
  const data = await res.json();
  svbLog('INVALIDATE response', data);
  if (!data.success) {
    throw new Error(data.data || 'Invalidate failed');
  }
  return data.data || {};
}

async function svbCreateInvoiceRequest(childCount, overlayData, segmentsValue, voicePayload, photoHashes, paymentRequired = true) {
  const fd = new FormData();
  fd.append('action', 'svb_create_invoice');
  fd.append('_svb_nonce', SVB_AJAX.nonce);
  fd.append('child_count', childCount);
  fd.append('selected_video_id', SVB_SELECTED_VIDEO_ID);
  fd.append('overlay_json', JSON.stringify(overlayData || {}));
  fd.append('segments', segmentsValue || '');
  fd.append('voice_payload', JSON.stringify(voicePayload || {}));
  fd.append('photos', JSON.stringify(photoHashes || []));
  fd.append('payment_required', paymentRequired ? '1' : '0');

  const saved = svbLoadState();
  const formData = saved && saved.formData ? saved.formData : {};
  if (formData.email) {
    fd.append('email', formData.email);
  }
  if (formData.customer_name) {
    fd.append('customer_name', formData.customer_name);
  }

  svbLog('[SVB CREATE INVOICE] request', {
    child_count: childCount,
    selected_video_id: SVB_SELECTED_VIDEO_ID,
    overlay_keys: Object.keys(overlayData || {}),
    has_segments: !!segmentsValue,
    photo_hashes: (photoHashes || []).length,
    payment_required: paymentRequired,
  });

  const res = await fetch(SVB_AJAX.url, { method: 'POST', body: fd });
  if (!res.ok) {
    svbError('[SVB CREATE INVOICE] network error', { status: res.status });
    throw new Error('Сервер помилки оплати');
  }

  const data = await res.json();
  if (!data.success) {
    svbError('[SVB CREATE INVOICE] error response', data);
    throw new Error(data.data || 'Оплата недоступна');
  }

  const payload = data.data || {};
  if (payload.order_id) {
    svbUpdateState({
      order_id: payload.order_id,
      public_token: payload.public_token,
    });
    svbStoreLastOrderToken(payload.order_id, payload.public_token || '');
  }

  return payload;
}

async function svbRequestInvoiceStatus(invoiceId) {
  const fd = new FormData();
  fd.append('action', 'svb_monobank_check_status');
  fd.append('_svb_nonce', SVB_AJAX.nonce);
  if (invoiceId) fd.append('invoice_id', invoiceId);

  const res = await fetch(SVB_AJAX.url, { method: 'POST', body: fd });
  if (!res.ok) {
    throw new Error('Помилка серверу перевірки оплати');
  }

  const data = await res.json();
  if (!data.success) {
    throw new Error(data.data || 'Не вдалося перевірити оплату');
  }

  return data.data;
}

async function svbPayDebugRequest(orderId) {
  const fd = new FormData();
  fd.append('action', 'svb_pay_debug_state');
  fd.append('_svb_nonce', SVB_AJAX.nonce);
  fd.append('order_id', orderId);

  const res = await fetch(SVB_AJAX.url, { method: 'POST', body: fd, credentials: 'same-origin' });
  if (!res.ok) {
    throw new Error('HTTP ' + res.status);
  }

  const data = await res.json();
  if (!data.success) {
    throw new Error(data.data || 'Sync error');
  }

  return data.data || {};
}

async function svbSyncPaymentStatus(orderId, token) {
  const fd = new FormData();
  fd.append('action', 'svb_monobank_sync_status');
  fd.append('_svb_nonce', SVB_AJAX.nonce);
  fd.append('order_id', orderId);
  if (token) {
    fd.append('token', token);
  }

  const res = await fetch(SVB_AJAX.url, { method: 'POST', body: fd, credentials: 'same-origin' });
  if (!res.ok) {
    throw new Error('HTTP ' + res.status);
  }

  const data = await res.json();
  if (!data.success) {
    throw new Error(data.data || 'Sync error');
  }

  return data.data || {};
}

async function svbOrderResumeInfoRequest(orderId, token) {
  const fd = new FormData();
  fd.append('action', 'svb_order_resume_info');
  fd.append('_svb_nonce', SVB_AJAX.nonce);
  fd.append('order_id', orderId);
  fd.append('token', token || '');

  const res = await fetch(SVB_AJAX.url, { method: 'POST', body: fd, credentials: 'same-origin' });
  if (!res.ok) throw new Error('HTTP ' + res.status);

  const json = await res.json();
  if (!json.success) throw new Error(json.data || 'Resume error');

  const data = json.data || {};

  if (data.found && data.can_regen && data.form_data) {
    try {
      console.log('[SVB RESUME] Injecting form data into state for paid order:', orderId);
      const restored = Object.assign({}, svbLoadState(), {
        child_count: data.form_data.child_count || 1,
        selected_video_id: data.form_data.selected_video_id || 'video1',
        formData: Object.assign({}, data.form_data || {}),
      });
      svbUpdateState(restored);
      const childSelect = document.getElementById('svb-child-count');
      if (childSelect) childSelect.value = restored.child_count;
    } catch (resumeErr) {
      console.log('[SVB RESUME][ERROR]', { message: resumeErr.message, stack: resumeErr.stack });
    }
  }

  const urlParams = new URLSearchParams(window.location.search);
  if (urlParams.has('svb_payment_return') || urlParams.get('order_id') === String(orderId)) {
    if (data.resume_url) {
      console.log('[SVB RESUME] Redirection suppressed to prevent loop');
      data.resume_url = ''; 
    }
  }

  return data;
}
function svbShowPaymentProcessing(message) {
  const box = document.getElementById('svb-payment-error');
  const text = box ? box.querySelector('.svb-payment-error__text') : null;
  if (text) {
    text.textContent = message || 'Оплата обробляється…';
  }
  if (box) {
    box.style.display = 'block';
  }
}

function svbProceedToGenerateFlow() {
  svbHidePaymentError();
  svbPersistStep2State();
  buildSoundMap();
  svbStartGenerate();
}

async function svbHandleStep2Next() {
  if (svbStep2InFlight) {
    console.log('[SVB PAY][STEP2] click ignored: in-flight');
    return;
  }
  const nextBtn = document.getElementById('svb-next-2');
  svbStep2InFlight = true;
  if (nextBtn) nextBtn.disabled = true;

  svbHidePaymentError();
  svbPersistStep2State();
  svbSerializeSegmentsToField();
  const childCount = svbGetSelectedChildCount();
  const savedState = svbLoadState();
  let overlayData = {};
  let segmentsValue = '';
  let voicePayload = {};

  try {
    overlayData = svbCollectOverlayData();
    const segField = document.getElementById('svb_segments');
    segmentsValue = segField && segField.value ? segField.value : '';
    buildSoundMap();
    voicePayload = Object.assign({}, SVB_SELECTED);
  } catch (collectErr) {
    svbError('[SVB GATE] collect failed', collectErr);
  }

  svbLog('[SVB STEP2] Next click', {
    payRequired: true,
    paymentEnabled: true,
    isAdmin: !!SVB_PAYMENT.is_admin,
    adminToggleFound: false,
    adminToggleChecked: true,
    child_count: childCount,
    selected_video_id: SVB_SELECTED_VIDEO_ID,
    formData: savedState.formData || {},
    overlayKeys: Object.keys(overlayData || {}),
  });

  console.groupCollapsed('[SVB PAY][STEP2] input');
  console.log({ child_count: childCount, selected_video_id: SVB_SELECTED_VIDEO_ID });
  console.groupEnd();
  try {
    const photoHashes = await svbCollectPhotoHashes();
    const adminSkipPayment = !!(SVB_PAYMENT.is_admin && svbIsPaymentDisabledByAdmin());
    const invoice = await svbCreateInvoiceRequest(childCount, overlayData, segmentsValue, voicePayload, photoHashes, !adminSkipPayment);

    if (invoice && invoice.order_id) {
      svbUpdateState({ order_id: invoice.order_id, public_token: invoice.public_token });
    }

    if (adminSkipPayment || (invoice && invoice.bypass)) {
      svbPaymentStatus = 'paid';
      svbProceedToGenerateFlow();
      return;
    }

    const paymentUrl = invoice.payment_url || '';
    if (paymentUrl) {
      console.log('[SVB PAY] payment_url=', svbMaskUrlToken(paymentUrl));
      console.log('[SVB PAY] redirecting…');
      svbStoreLastOrderToken(invoice.order_id, invoice.public_token || '');
      svbSaveLastPaymentAttempt({
        order_id: invoice.order_id,
        public_token: invoice.public_token || '',
        pageUrl: paymentUrl,
        invoiceId: invoice.invoice_id || '',
        fingerprint_prefix: '',
      });
      window.location = paymentUrl;
      return;
    }

    svbShowPaymentError('Не вдалося отримати лінк на оплату. Спробуйте ще раз.');
  } catch (err) {
    svbError('[SVB PAY] invoice creation failed', err);
    if (svbIsDebugMode()) {
      alert((err && err.message) ? err.message : 'Invoice creation failed');
    }
    svbShowPaymentError(err.message || 'Оплата тимчасово недоступна.');
  } finally {
    svbStep2InFlight = false;
    if (nextBtn) nextBtn.disabled = false;
  }
}

async function svbCheckInvoiceOnReturn() {
  const params = new URLSearchParams(window.location.search);
  const isReturn = params.has('svb_payment_return') || params.has('svb_payment_success') || params.has('svb_payment_fail');
  if (svbPaymentReturnHandled && isReturn) {
    return;
  }
  if (isReturn) {
    await svbHandlePaymentReturnFlow(params);
    return;
  }

  if (svbPaymentStatus === 'paid') return;

  const invoiceId = params.get('invoiceId') || svbGetStoredInvoiceId() || (SVB_PAYMENT.invoice_id || '');
  if (!invoiceId) return;

  try {
    const status = await svbRequestInvoiceStatus(invoiceId);
    if (status.status === 'paid') {
      svbPaymentStatus = 'paid';
      SVB_PAYMENT.status = 'paid';
      svbClearInvoiceId();
      svbProceedToGenerateFlow();
    }
  } catch (err) {
    console.error(err);
  }
}

async function svbHandlePaymentDecision(orderId, state, token, fromPendingSync = false) {
  const successStates = ['paid', 'success'];
  const failureStates = ['failed', 'failure', 'no_invoice', 'mono_error', 'no_order'];
  const pendingStates = ['pending', 'processing'];
  let decision = state.decision || 'pending';
  const savedState = svbLoadState();
  const tokenForMark = token || (savedState && savedState.public_token ? savedState.public_token : '');

  const markPaid = () => {
    svbHidePendingNotice();
    svbPaymentStatus = 'paid';
    SVB_PAYMENT.status = 'paid';
    svbClearInvoiceId();
    svbSaveLastPaidOrder(orderId, tokenForMark, state.paid_fingerprint || state.fingerprint_current);
    svbProceedToGenerateFlow();
  };

  if (successStates.includes(decision)) {
    markPaid();
    return;
  }

  if (failureStates.includes(decision) || decision === 'not_paid') {
    svbHidePendingNotice();
    svbClearInvoiceId();
    svbSetStep(2);
    svbShowPaymentError('Оплата не підтверджена. Спробуйте оновити сторінку або зверніться в підтримку.');
    console.log('[SVB PAY][SYNC]', { order_id: orderId, decision, reason: state.reason || '' });
    return;
  }

  if (!pendingStates.includes(decision)) {
    svbShowPaymentError('Оплата ще не підтверджена. Спробуйте ще раз.');
    return;
  }

  const pageUrl = state.pageUrl || state.invoice_page_url || '';
  const invoiceId = state.invoice_id || state.invoiceId || '';
  const invoiceFingerprint = state.invoice_fingerprint || '';
  const childCount = state.child_count || svbGetSelectedChildCount();

  svbHidePaymentError();
  svbShowPendingNotice('Оплата обробляється. Очікуємо підтвердження…', {
    order_id: orderId,
    token: tokenForMark,
    pageUrl,
    invoice_id: invoiceId,
    invoice_fingerprint: invoiceFingerprint,
    fingerprint_current: state.fingerprint_current,
    child_count: childCount,
  });

  if (!fromPendingSync) {
    svbStartPendingPoll();
  } else if (!SVB_PENDING.timer) {
    svbStartPendingPoll();
  }
}

async function svbHandlePaymentReturnFlow(params) {
  if (svbPaymentReturnHandled) {
    return;
  }
  svbPaymentReturnHandled = true;
  const isPaymentReturn = params.has('svb_payment_return');
  const savedState = svbEnsureStateStructure();
  const lastAttempt = svbLoadLastPaymentAttempt();
  const storedOrder = svbLoadLastOrderToken();
  const hasState = !!savedState;
  const urlOrderId = parseInt(params.get('order_id') || '0', 10);
  const urlToken = params.get('token') || params.get('svb_order') || params.get('svb_token') || '';
  const paymentAttemptId = lastAttempt && lastAttempt.order_id ? lastAttempt.order_id : null;
  const orderId = urlOrderId || paymentAttemptId || (savedState && savedState.order_id) || (storedOrder && storedOrder.order_id) || 0;
  const token = urlToken || (lastAttempt && lastAttempt.public_token) || (savedState && savedState.public_token) || (storedOrder && storedOrder.token) || '';

  if (orderId && token) {
    svbStoreLastOrderToken(orderId, token);
  }

  console.log('[SVB PAY][RETURN]', {
    hasLastPaymentAttempt: !!lastAttempt,
    order_id: orderId || null,
    invoice_masked: lastAttempt && lastAttempt.invoice_masked ? lastAttempt.invoice_masked : '',
    hasState,
    isPaymentReturn,
    step: savedState && savedState.step ? savedState.step : null,
  });

  await svbFetchSessionDebug('payment_return');

  try {
    const state = await svbSyncPaymentStatus(orderId, token);
    const resolvedOrderId = state.order_id || orderId;
    if (resolvedOrderId && token) {
      svbStoreLastOrderToken(resolvedOrderId, token);
    }
    console.log('[SVB PAY][SYNC]', {
      decision: state.decision,
      payment_status: state.payment_status,
      paid_fingerprint: state.paid_fingerprint,
      fingerprint_current: state.fingerprint_current,
      reason: state.reason,
    });

    if (['pending', 'processing'].includes(state.payment_status) || state.decision === 'pending') {
      svbShowPendingNotice('Оплата ще не підтверджена. Очікуємо…', {
        order_id: resolvedOrderId,
        token,
        pageUrl: state.pageUrl || (lastAttempt ? lastAttempt.pageUrl : '') || '',
        invoice_id: state.invoice_id || (lastAttempt ? lastAttempt.invoiceId : '') || '',
        fingerprint_current: state.fingerprint_current || (lastAttempt ? lastAttempt.fingerprint_prefix : '') || '',
        child_count: savedState.child_count || 1,
      });
      svbStartPendingPoll();
      return;
    }

    if (state.decision === 'paid' || state.decision === 'success' || state.is_paid) {
      svbSaveLastPaidOrder(resolvedOrderId, token, state.fingerprint_current || state.paid_fingerprint);
      svbUpdateState({ order_id: resolvedOrderId, public_token: token, payment_status: 'success' });

      try {
        const resumeInfo = await svbOrderResumeInfoRequest(resolvedOrderId, token);
        if (resumeInfo && resumeInfo.order_id) {
          svbShowRecoverModal({
            order_id: resumeInfo.order_id,
            has_video: !!resumeInfo.has_video,
            can_regen: !!resumeInfo.can_regen,
            resume_url: resumeInfo.resume_url || '',
          });
        }
        await svbHandlePaidResume(resolvedOrderId, token, resumeInfo || {});
      } catch (resumeErr) {
        console.log('[SVB RESUME][ERROR]', { message: resumeErr.message, stack: resumeErr.stack });
        svbSetStep(2);
        svbRestoreStep2State();
      }

      const cleanUrl = window.location.protocol + '//' + window.location.host + window.location.pathname;
      window.history.replaceState({}, document.title, cleanUrl);
      svbClearLastPaymentAttempt();
      return;
    }

    svbShowPaymentError('Оплата не підтверджена. Спробуйте ще раз.');
  } catch (err) {
    console.error(err);
    svbShowPaymentError('Не вдалося синхронізувати оплату: ' + err.message);
  }
}

let svbJobToken = null, svbVideoURL = null, svbGenerating = false;
let svbPollInterval = null;
const next2Btn = document.getElementById('svb-next-2');
if (next2Btn) {
  next2Btn.addEventListener('click', svbHandleStep2Next);
}
const restoreBtn = document.getElementById('svb-restore-btn');
if (restoreBtn) {
  restoreBtn.addEventListener('click', async () => {
    const emailField = document.getElementById('svb-restore-email');
    const orderField = document.getElementById('svb-restore-order');
    const statusBox = document.getElementById('svb-restore-status');
    const email = emailField ? emailField.value.trim() : '';
    const orderId = orderField ? parseInt(orderField.value, 10) : 0;
    if (!email || !orderId) {
      if (statusBox) {
        statusBox.style.display = 'block';
        statusBox.textContent = 'Вкажіть email та номер замовлення.';
      }
      return;
    }

    if (statusBox) {
      statusBox.style.display = 'block';
      statusBox.textContent = 'Шукаємо замовлення...';
    }

    try {
      const fd = new FormData();
      fd.append('action', 'svb_resume_by_identity');
      fd.append('_svb_nonce', SVB_AJAX.nonce);
      fd.append('email', email);
      fd.append('order_id', orderId);
      const res = await fetch(SVB_AJAX.url, { method: 'POST', body: fd });
      const data = await res.json();
      if (!data.success) {
        throw new Error(data.data || 'Не вдалося відновити');
      }
      const payload = data.data || {};
      svbUpdateState(Object.assign({}, svbLoadState(), {
        order_id: payload.order_id,
        public_token: payload.public_token,
        child_count: payload.child_count,
        selected_video_id: payload.selected_video_id,
        overlay_json: payload.overlay_json,
        segments: payload.segments,
        formData: Object.assign({}, svbLoadState().formData || {}, { email }),
      }));
      svbStoreLastOrderToken(payload.order_id, payload.public_token || '');
      if (statusBox) {
        statusBox.textContent = 'Замовлення знайдено. Переходимо до генерації...';
      }
      svbSetStep(3);
      svbStartGenerate();
    } catch (resumeErr) {
      if (statusBox) {
        statusBox.textContent = resumeErr.message || 'Не вдалося відновити замовлення';
      }
    }
  });
}
const paymentRetryBtn = document.getElementById('svb-payment-retry');
if (paymentRetryBtn) {
  paymentRetryBtn.addEventListener('click', () => {
    svbPaymentStatus = 'unpaid';
    svbClearInvoiceId();
    svbHidePaymentError();
    svbHandleStep2Next();
  });
}
const paymentBackBtn = document.getElementById('svb-payment-back');
if (paymentBackBtn) {
  paymentBackBtn.addEventListener('click', () => {
    svbPaymentStatus = 'unpaid';
    svbClearInvoiceId();
    svbHidePaymentError();
    svbSetStep(2);
    svbRestoreStep2State();
  });
}
  $('#svb-back-3').addEventListener('click', ()=> {
    svbSetStep(2);
    if (svbPollInterval) clearInterval(svbPollInterval);
    svbRestoreStep2State();
  });
    $('#svb-finish').addEventListener('click', async ()=>{
      const email = $('#svb-email').value.trim();
      if(!email){ alert('Вкажіть email'); return; }
    if(!svbVideoURL){
      alert('Відео ще готується. Будь ласка, дочекайтесь повідомлення «Готово».');
      return;
    }
    if (svbRecoveredFromLookup && svbVideoURL) {
      await svbNavigateToDownload(svbVideoURL);
      return;
    }
    const fd = new FormData();
    fd.append('action','svb_confirm');
    fd.append('_svb_nonce', SVB_AJAX.nonce);
    fd.append('email', email);
    fd.append('token', svbJobToken||'');
  fetch(SVB_AJAX.url, { method:'POST', body:fd })
    .then(r=>r.json()).then(data=>{
      if (data.success) {
  document.getElementById('svb-status').textContent = '✅ Готово';
  svbRenderResultVideo(data.data.url);
} else {
  alert(data.data || 'Помилка підтвердження');
}

    });
  });
  function escapeHtml(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
  function svbToggleVideoOverlay(show){
    const overlay = $('#svb-video-overlay');
    if (!overlay) return;
    overlay.classList.toggle('svb-video-overlay--hidden', !show);
  }

  const SVB_POSTER_URL = 'https://e-santaa.com/wp-content/uploads/2025/11/posEr.png';

function svbShowPoster(show) {
  const poster = document.getElementById('svb-video-poster') || document.querySelector('.svb-video-bg');
  if (!poster) return;
  if (!poster.getAttribute('src')) poster.setAttribute('src', SVB_POSTER_URL);
  poster.style.display = show ? '' : 'none';
}

function svbRenderResultVideo(url) {
  const res = document.getElementById('svb-result');
  if (!res) return;

  // убираем мета-окно если оно есть
  res.querySelectorAll('.svb-video-result-meta').forEach(n => n.remove());

  let video = res.querySelector('video');
  if (!video) {
    video = document.createElement('video');
    video.controls = true;
    video.playsInline = true;
    video.controlsList = 'nodownload';
    res.appendChild(video);
  }

  video.src = url;
  video.load();
  video.play().catch(()=>{});

  res.style.display = 'block';
  svbShowPoster(false);
}


  function svbResetPreviewState() {
    const res = $('#svb-result');
    if (!res) return;

    const existingVideo = res.querySelector('video');
    if (existingVideo) {
      try { existingVideo.pause(); } catch (e) {}
      existingVideo.removeAttribute('src');
      try { existingVideo.load(); } catch (e) {}
    }

    res.innerHTML = '';
    res.style.display = 'none';
    svbVideoURL = null;

    const finishBtn = $('#svb-finish');
    if (finishBtn) finishBtn.disabled = true;
    svbShowPoster(true);

  }

  function svbUpdateVideoPercent(percent) {
    const percentBox = $('#svb-video-percent');
    if (percentBox) {
      percentBox.textContent = `Створення відео – ${percent}%`;
    }
  }
  function svbHandleError(err) {
    if (svbPollInterval) clearInterval(svbPollInterval);
    svbGenerating = false;
    svbToggleVideoOverlay(false);
    svbShowPoster(true);

    const msg = (err && err.msg) ? err.msg : 'Сталася помилка під час генерації відео.';
    const status = document.getElementById('svb-status');
    if (status) status.textContent = msg;

    if (svbIsDebugMode() && err && err.log) {
      console.error('[SVB GENERATE ERROR]', err.log);
    }

    svbSetStep(2);
  }

  function svbMarkGenerationStarted() {
    svbUpdateState({ step: 3 });
    svbSetStep(3);
    svbShowPoster(true);
    svbToggleVideoOverlay(true);
    svbUpdateVideoPercent(0);
    $('#svb-status').textContent = 'Генеруємо відео… це може зайняти кілька хвилин';
  }

  async function svbStartGenerate() {
      if (svbGenerating) return;
      svbGenerating = true;
      svbRecoveredFromLookup = false;
      svbLookupDownloadUrl = '';
      svbResetPreviewState();
      $('#svb-status').textContent = 'Збирання даних...';

    // 1. Сохраняем сегменты времени
    svbSerializeSegmentsToField();
    

    const form = document.getElementById('svb-form');
    const fd = new FormData(form);
    
    // Передаем ID видео
    fd.set('selected_video_id', SVB_SELECTED_VIDEO_ID);
    
    // 2. Собираем данные геометрии (JSON)
    let overlayData = {};
    try {
        overlayData = svbCollectOverlayData();
        fd.append('overlay_json', JSON.stringify(overlayData));
    } catch (jsonErr) {
        console.error('overlay_json encode failed:', jsonErr);
    }

    // 3. === FIX: СБОР VISUAL DEBUG ДАННЫХ (С учетом скрытых элементов) ===
    const visualDebug = {};
    
    // Временно показываем Step 2, чтобы считать реальные размеры
    const step2 = document.querySelector('.svb-step[data-step="2"]');
    // Проверяем, скрыт ли блок сейчас
    const wasHidden = step2 && (getComputedStyle(step2).display === 'none');
    
    if (wasHidden && step2) {
        // Делаем видимым для браузера, но прозрачным для глаза
        step2.style.visibility = 'hidden'; 
        step2.style.display = 'block';
    }

    ['child1', 'child2', 'parent1', 'parent2'].forEach(key => {
        const img = document.getElementById('img-' + key);
        const vid = document.getElementById('svb-video-' + key); // Контейнер превью
        
        if (img && vid) {
            // Принудительно обновляем трансформацию перед замером
            if (typeof svbUpdatePreviewTransform === 'function') svbUpdatePreviewTransform(key);

            const rect = img.getBoundingClientRect();
            const vidRect = vid.getBoundingClientRect();
            const computed = window.getComputedStyle(img);
            
            visualDebug[key] = {
                client_rect: {
                    top: rect.top,
                    left: rect.left,
                    width: rect.width,
                    height: rect.height
                },
                video_rect: {
                    width: vidRect.width,
                    height: vidRect.height
                },
                css: {
                    transform: computed.transform,
                    width: computed.width,
                    height: computed.height,
                    // Добавляем отступы, чтобы понять позицию внутри родителя
                    offsetLeft: img.offsetLeft,
                    offsetTop: img.offsetTop
                },
                natural_ratio: (img.naturalWidth / (img.naturalHeight || 1)) || 1
            };
        }
    });

    // Возвращаем как было
    if (wasHidden && step2) {
        step2.style.display = 'none';
        step2.style.visibility = '';
    }

    fd.append('debug_front_visuals', JSON.stringify(visualDebug));
    // ==========================================

    fd.append('action', 'svb_generate');

    try {
        const response = await fetch(SVB_AJAX.url, { method:'POST', body:fd });
    
        if (!response.ok) {
            const text = await response.text(); 
            console.error('Server Error:', response.status, text);
            throw new Error(`Server responded with ${response.status}: ${text.substring(0, 200)}`);
        }

        const data = await response.json();

          if (data.success && data.data && data.data.token) {
              svbMarkGenerationStarted();
              svbJobToken = data.data.token;
              $('#svb-status').textContent = 'Генерація почалася...';
              svbPollProgress(svbJobToken);
          } else {
              svbHandleError(data.data || {msg: 'Сервер повернув помилку (success: false).'});
        }
    } catch (err) {
        console.error(err);
        svbHandleError({
            msg: 'Критична помилка відправки даних.',
            log: err.message + "\n\nПеревірте розмір фото (upload_max_filesize)!"
        });
    }
}
function svbPollProgress(token) {
  if (svbPollInterval) clearInterval(svbPollInterval);
  svbPollInterval = setInterval(async () => {
    try {
      const fd = new FormData();
      fd.append('action', 'svb_check_progress');
      fd.append('_svb_nonce', SVB_AJAX.nonce);
      fd.append('token', token);
      const response = await fetch(SVB_AJAX.url, { method: 'POST', body: fd });
      const data = await response.json();
      
      if (data.success) {

        // === DEBUG OUTPUT TO CONSOLE ===
        if (data.data.debug) {
            console.groupCollapsed(`🚀 SVB Progress: ${data.data.percent}%`);
            console.log("Log Exists:", data.data.debug.log_exists);
            console.log("Log Size:", data.data.debug.log_size);
            console.log("%cFFmpeg Log Tail:", "color: orange; font-weight: bold;");
            console.log(data.data.debug.tail);
            console.groupEnd();
        }
        // ===============================

          if (data.data.status === 'running') {
            const percent = data.data.percent || 0;
            svbUpdateVideoPercent(percent);
            $('#svb-status').textContent = ``;
          } else if (data.data.status === 'done') {
            clearInterval(svbPollInterval);
          if (data.data && data.data.url) {
            svbHandleSuccess(data.data.url);
          } else {
            svbHandleError({ msg: 'Не вдалося отримати посилання на відео.' });
          }
        }
      } else {
        clearInterval(svbPollInterval);
        svbHandleError(data.data || {msg: 'Помилка під час перевірки статусу.'});
      }
    } catch (err) {
      clearInterval(svbPollInterval);
      svbHandleError({msg: 'Помилка мережі під час перевірки статусу.', log: err.message});
    }
  }, 3000);
}

// Diagnostics: check download endpoint response before navigating (open browser Console to inspect status, content-type, final URL, headers).
async function svbProbeDownload(url, options = {}) {
    const force = !!options.force;
    if (!force && !svbIsDebugMode()) {
        return { okForVideo: true, reason: 'debug_off' };
    }

    const maskedUrl = svbMaskUrlToken(url || '');
    svbLogDownload('Probe start', { requestedUrl: maskedUrl, forced: force });

    try {
        const response = await fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            redirect: 'follow',
            headers: {
                Range: 'bytes=0-512'
            }
        });

        const contentType = response.headers.get('content-type') || '';
        const contentDisposition = response.headers.get('content-disposition') || '';
        const contentLength = response.headers.get('content-length') || '';
        const dlHeader = response.headers.get('x-svb-download') || '';
        const dlReason = response.headers.get('x-svb-download-reason') || '';

        svbLogDownload('Probe response', {
            requestedUrl: maskedUrl,
            status: response.status,
            ok: response.ok,
            redirected: response.redirected,
            finalUrl: svbMaskUrlToken(response.url || ''),
            headers: {
                'content-type': contentType,
                'content-disposition': contentDisposition,
                'content-length': contentLength,
                'x-svb-download': dlHeader,
                'x-svb-download-reason': dlReason
            }
        });

        if (response.status === 404) {
            return { okForVideo: false, reason: dlReason || 'status 404' };
        }

        if (contentType.toLowerCase().startsWith('text/')) {
            const body = await response.text();
            svbLogDownload('Probe body (first 800 chars)', body.slice(0, 800));
            return { okForVideo: false, reason: 'content-type ' + contentType };
        }

        if (!contentType.toLowerCase().startsWith('video/')) {
            return { okForVideo: false, reason: 'content-type ' + (contentType || 'unknown') };
        }

        if (!(response.ok || response.status === 206)) {
            return { okForVideo: false, reason: 'status ' + response.status };
        }

        return { okForVideo: true, reason: dlReason || 'ok' };
    } catch (err) {
        svbErrorDownload('Probe failed', err);
        return { okForVideo: false, reason: err && err.message ? err.message : 'probe_failed' };
    }
}

async function svbNavigateToDownload(url) {
    if (!url) return;
    if (!svbIsDebugMode()) {
        window.location.href = url;
        return;
    }

    const probe = await svbProbeDownload(url);
    if (probe.okForVideo) {
        window.location.href = url;
    } else {
        alert('Download failed: ' + (probe.reason || 'unknown'));
    }
}

function svbAttachDownloadDebug(container, url) {
    if (!container || !url) return;
    const anchors = container.querySelectorAll('.svb-download-link');
    anchors.forEach(a => {
        a.addEventListener('click', async (e) => {
            const maskedUrl = svbMaskUrlToken(url);
            const payload = {
              action: 'clicked_open_download',
              url: maskedUrl,
              preventedDefault: true,
              navigationAttempted: true,
            };
            if (svbIsDebugMode()) {
              svbLogDownload('Download click', payload);
            }
            e.preventDefault();
            await svbNavigateToDownload(url);
        });
    });
}

function svbHandleSuccessInternal(url) {
    if (!url) {
        svbHandleError({ msg: 'Не вдалося отримати посилання на відео.' });
        return;
    }

    const maskedUrl = svbMaskUrlToken(url);
    svbLogDownload('Download URL issued', maskedUrl);
    svbGenerating = false;
    svbToggleVideoOverlay(false);
    svbVideoURL = url;
    svbUpdateVideoPercent(100);
    const statusEl = $('#svb-status');
    if (statusEl) {
        statusEl.innerHTML = `✅ Відео зібрано. <a class="svb-download-link" href="${url}" download>Скачати</a>`;
        svbAttachDownloadDebug(statusEl, url);
    }
    const res = $('#svb-result');
    if (res) {
      const video = document.createElement('video');
      video.src = url;
      video.controls = true;
      video.playsInline = true;
      video.controlsList = 'nodownload';

      const meta = document.createElement('div');
      meta.className = 'svb-video-result-meta';
      meta.innerHTML = `<b>Готово!</b> <a class="svb-download-link" href="${url}" download>Скачати відео</a>. Посилання дійсне 1 годину.`;

      res.innerHTML = '';
      res.appendChild(video);
      res.appendChild(meta);
      res.style.display = 'block';
      svbAttachDownloadDebug(res, url);
    }
    if (svbIsDebugMode()) {
        svbProbeDownload(url);
    }
    $('#svb-finish').disabled = false;
}
function svbHandleSuccess(url) {
    // Wrapper to keep legacy references working while ensuring download probes/logs.
    svbHandleSuccessInternal(url);
}


const _svbNorm = s => (s||'').toString().toLowerCase().trim().replace(/[\s_\-’']/g,'');

function svbMarkTouched(key){
  ['x','y','scale','scale_x','scale_y','skew','skew_y','angle','radius','opacity','glow'].forEach(k=>{
    const el = document.querySelector(`input[name="${key}_${k}"]`);
    if (el && !el.__svb_bound) {
      el.addEventListener('input', ()=> el.dataset.touched = '1');
      el.__svb_bound = true;
    }
  });
}
// 1. Збираємо всі імена (хлопчики, дівчатка, root) в один список
function getAllNames() {
    let all = [];
    if (typeof SVB_AUDIO !== 'undefined' && SVB_AUDIO.name) {
        if (SVB_AUDIO.name.boy) all = all.concat(SVB_AUDIO.name.boy);
        if (SVB_AUDIO.name.girl) all = all.concat(SVB_AUDIO.name.girl);
        if (SVB_AUDIO.name.root) all = all.concat(SVB_AUDIO.name.root);
    }
    return all;
}

function svbFindNameAudioUrl(file) {
    if (!file) return '';
    const match = getAllNames().find(i => i.file === file);
    return match && match.url ? match.url : '';
}

function svbUpdateNamePlayButton(playBtn, file) {
    if (!playBtn) return;
    const audioUrl = svbFindNameAudioUrl(file);
    if (audioUrl) {
        playBtn.dataset.audioUrl = audioUrl;
        playBtn.disabled = false;
        playBtn.style.display = 'inline-flex';
    } else {
        playBtn.dataset.audioUrl = '';
        playBtn.disabled = true;
    }
}

function svbBindNamePlayButtons() {
    const pairs = [
        { select: 'name_audio', btn: 'svb-name-play-1' },
        { select: 'name_audio_2', btn: 'svb-name-play-2' },
        { select: 'name_audio_3', btn: 'svb-name-play-3' },
    ];

    pairs.forEach(({ select, btn }) => {
        const buttonEl = document.getElementById(btn);
        if (!buttonEl) return;

        const newBtn = buttonEl.cloneNode(true);
        buttonEl.parentNode.replaceChild(newBtn, buttonEl);

        const selectEl = document.querySelector(`select[name="${select}"]`);
        const applyFromSelect = () => {
            const file = selectEl ? selectEl.value : '';
            svbUpdateNamePlayButton(newBtn, file);
        };

        applyFromSelect();
        if (selectEl) {
            selectEl.addEventListener('change', applyFromSelect);
        }

        newBtn.addEventListener('click', async () => {
            const url = newBtn.dataset.audioUrl;
            if (!url) return;

            newBtn.classList.add('is-loading');
            newBtn.disabled = true;

            try {
                if (svbCurrentSampleAudio) {
                    svbCurrentSampleAudio.pause();
                    svbCurrentSampleAudio = null;
                }
                const audio = new Audio(url);
                svbCurrentSampleAudio = audio;
                await audio.play();
            } catch (err) {
                console.warn('[SVB] Failed to play name sample', err);
            } finally {
                newBtn.classList.remove('is-loading');
                newBtn.disabled = false;
            }
        });
    });
}

// Замените существующую функцию svbBindNameSuggestUniversal на эту:
function svbBindNameSuggestUniversal(inputId, resultBoxId, selectName, displayId, playBtnId) {
    const input = document.querySelector(`input[name="${inputId}"]`);
    const box = document.getElementById(resultBoxId);
    const sel = document.querySelector(`select[name="${selectName}"]`);
    const display = document.getElementById(displayId);
    const playBtn = document.getElementById(playBtnId);

    if (!input || !box || !sel) return;

    // Поиск
    const doSearch = (val) => {
        const list = getAllNames();
        const normQ = _svbNorm(val);
        const items = list.filter(i => !normQ || _svbNorm(i.label || i.file).includes(normQ)).slice(0, 10);
        
        box.innerHTML = items.map(i => 
            `<div class="svb-suggest-item" data-file="${i.file}" data-label="${i.label || i.file}">
                ${i.label || i.file} <small style="color:#999">(${i.file})</small>
             </div>`
        ).join('');
        
        if (items.length > 0 && normQ) {
            box.style.display = 'block';
            // Базовая стилизация выпадающего списка
            box.style.position = 'absolute';
            box.style.zIndex = '1000';
            box.style.background = '#fff';
            box.style.border = '1px solid #ddd';
            box.style.width = '100%';
            box.style.maxHeight = '200px';
            box.style.overflowY = 'auto';
        } else {
            box.style.display = 'none';
        }
    };

    input.addEventListener('input', e => {
        doSearch(e.target.value);
        if(playBtn) playBtn.style.display = 'none'; // Скрываем кнопку при наборе
    });
    input.addEventListener('focus', e => doSearch(e.target.value));

    // Клик по списку
    box.addEventListener('click', e => {
        const item = e.target.closest('.svb-suggest-item');
        if (!item) return;
        
        const label = item.dataset.label;
        const file = item.dataset.file;

        input.value = label;
        sel.innerHTML = `<option value="${file}" selected>${label}</option>`;
        sel.value = file;
        
        if (display) {
            display.textContent = `✅ Обрано: ${label}`;
            display.style.color = 'green';
        }

        // === ЛОГИКА КНОПКИ PLAY ===
        if (playBtn) {
            svbUpdateNamePlayButton(playBtn, file);
        }

        box.style.display = 'none';
    });

    document.addEventListener('click', e => {
        if (!e.target.closest('.svb-suggest') && e.target !== input) {
            box.style.display = 'none';
        }
    });
}


function svbBindRealtimeControls() {
    ['child1', 'child2', 'parent1', 'parent2'].forEach(key => {
        const vid = document.getElementById(`svb-video-${key}`);
        const playBtn = document.querySelector(`[data-vid-ctrl="play"][data-key="${key}"]`);
        const pauseBtn = document.querySelector(`[data-vid-ctrl="pause"][data-key="${key}"]`);
        const timeEl = document.getElementById(`svb-vid-time-${key}`);
        const muteBtn = document.querySelector(`[data-vid-ctrl="mute"][data-key="${key}"]`);
        const unmuteBtn = document.querySelector(`[data-vid-ctrl="unmute"][data-key="${key}"]`);
        const volumeSlider = document.querySelector(`[data-vid-ctrl="volume"][data-key="${key}"]`);
        const seekSlider = document.querySelector(`[data-vid-ctrl="seek"][data-key="${key}"]`);
                const timeInputMs = document.querySelector(`.svb-time-input[data-key="${key}"]`);
                const img = document.getElementById('img-' + key);
        function isOn(t){
            const windows = (typeof SVB_OVERLAY_WINDOWS === 'object' && SVB_OVERLAY_WINDOWS[key]) ? SVB_OVERLAY_WINDOWS[key] : [];
            for (let i = 0; i < windows.length; i++){
                const w = windows[i];
                if (t >= (w[0]||0) && t <= (w[1]||0)) return true;
            }
            return false;
        }


        function updateImgVisibility(timeSec) {
            if (!img) return;
            const baseAlpha = parseFloat(img.dataset.svbOpacity || '1') || 1;
            const visible   = isOn(timeSec);
            img.dataset.svbVisible = visible ? '1' : '0';
            const alpha = visible ? baseAlpha : 0;
            img.style.opacity = String(alpha);
        }

        if (img) {
            updateImgVisibility(0);
        }

        if (!vid || !playBtn || !pauseBtn || !timeEl || !muteBtn || !unmuteBtn || !volumeSlider || !seekSlider) {
            return;
        }


        playBtn.addEventListener('click', () => {
            if(svbCurrentSampleAudio) {
                svbCurrentSampleAudio.pause();
                svbCurrentSampleAudio = null;
            }
            vid.play();
        });
        pauseBtn.addEventListener('click', () => {
            vid.pause();
        });
        vid.addEventListener('play', () => {
            playBtn.style.display = 'none';
            pauseBtn.style.display = 'inline-flex';
        });
        vid.addEventListener('pause', () => {
            playBtn.style.display = 'inline-flex';
            pauseBtn.style.display = 'none';
        });

        let totalDuration = 0;
        vid.addEventListener('loadedmetadata', () => {
            totalDuration = vid.duration;
            seekSlider.max = totalDuration;

                        let start = 0;
            const currentWindows = (typeof SVB_OVERLAY_WINDOWS === 'object' && SVB_OVERLAY_WINDOWS[key]) ? SVB_OVERLAY_WINDOWS[key] : [];
            if (currentWindows && currentWindows.length) {
                start = currentWindows[0][0] || 0;
            }


            vid.currentTime = start;
            seekSlider.value = start;
            if (img) updateImgVisibility(start);

            timeEl.textContent = `${svbFormatTime(start)} / ${svbFormatTime(totalDuration)}`;

            if (timeInputMs) {
                timeInputMs.value = Math.round(start * 1000);
            }
        });




        vid.addEventListener('timeupdate', () => {
            const currentTime = vid.currentTime;
            if (!seekSlider.matches(':active')) {
                seekSlider.value = currentTime;
            }
            timeEl.textContent = `${svbFormatTime(currentTime)} / ${svbFormatTime(totalDuration || vid.duration || 0)}`;
            if (timeInputMs) {
                timeInputMs.value = Math.round(currentTime * 1000);
            }
            if (img) {
                updateImgVisibility(currentTime);
            }
        });



        seekSlider.addEventListener('input', (e) => {
            const t = parseFloat(e.target.value) || 0;
            vid.currentTime = t;
            if (timeInputMs) {
                timeInputMs.value = Math.round(t * 1000);
            }
            timeEl.textContent = `${svbFormatTime(t)} / ${svbFormatTime(totalDuration || vid.duration || 0)}`;
            if (img) {
                updateImgVisibility(t);
            }
        });



        const updateMuteButtons = (isMuted) => {
            muteBtn.style.display = isMuted ? 'none' : 'inline-flex';
            unmuteBtn.style.display = isMuted ? 'inline-flex' : 'none';

            if (isMuted) {
                volumeSlider.value = 0;
            } else {
                if (vid.volume < 0.05) {
                    vid.volume = 0.8;
                }
                volumeSlider.value = vid.volume;
            }
        };
        muteBtn.addEventListener('click', () => {
            vid.muted = true;
        });
        unmuteBtn.addEventListener('click', () => {
            vid.muted = false;
        });
        volumeSlider.addEventListener('input', (e) => {
            const vol = parseFloat(e.target.value);
            vid.volume = isNaN(vol) ? vid.volume : vol;
            vid.muted = (vol < 0.05);
        });

        vid.addEventListener('volumechange', () => {
             updateMuteButtons(vid.muted || vid.volume < 0.05);
        });
        vid.volume = parseFloat(volumeSlider.value || '0.8');
        vid.muted = vid.volume < 0.05;
        updateMuteButtons(vid.muted);
                if (timeInputMs) {
            timeInputMs.addEventListener('input', () => {
                const ms = parseFloat(timeInputMs.value);
                if (!Number.isFinite(ms)) return;

                const dur = totalDuration || vid.duration || 0;
                let sec = ms / 1000;
                if (sec < 0) sec = 0;
                if (dur > 0 && sec > dur) sec = dur;

                vid.currentTime = sec;
                seekSlider.value = sec;
                timeEl.textContent = `${svbFormatTime(sec)} / ${svbFormatTime(dur)}`;
                if (img) {
                    updateImgVisibility(sec);
                }
            });
        }

        const controls = {};
        const logBtn = document.querySelector(`[data-vid-ctrl="log"][data-key="${key}"]`);
if (logBtn) {
  logBtn.addEventListener('click', () => {
    const img = document.getElementById('img-' + key);
    svbDumpOverlayDebug(img, vid, key, svbJobToken);
    alert('Кадр зафиксирован в логе (svb_align.jsonl).');
  });
}

        $$(`.svb-key-control[name^="${key}_"]`).forEach(input => {
            const keyUp = input.dataset.keyUp;
            const keyDown = input.dataset.keyDown;
            if (keyUp) controls[keyUp] = { input, dir: 1 };
            if (keyDown) controls[keyDown] = { input, dir: -1 };
        });
        $$(`.svb-key-control[name^="${key}_"]`).forEach(slider => {
            slider.addEventListener('keydown', (e) => {
                const ctrl = controls[e.key];
                if (!ctrl) return;
                e.preventDefault();
                const input = ctrl.input;
                const dir = ctrl.dir;
                const step = parseFloat(input.step) || 1;
                const min = parseFloat(input.min) || -Infinity;
                const max = parseFloat(input.max) || Infinity;
                let val = parseFloat(input.value) || 0;
                val += dir * (e.shiftKey ? step * 10 : step);
                input.value = Math.max(min, Math.min(max, val)).toFixed(0);
                input.dispatchEvent(new Event('input', { bubbles: true }));
            });
        });
    });
}
function svbEnsureWrappers(){
  ['child1','child2','parent1','parent2'].forEach(key=>{
    const img = document.getElementById('img-'+key);
    const box = document.getElementById('svb-vid-preview-'+key);
    if(!img || !box) return;
    if (img.parentElement && img.parentElement.classList.contains('svb-ovbox')) return;

    const wrap = document.createElement('div');
    wrap.className = 'svb-ovbox';
    box.appendChild(wrap);
    wrap.appendChild(img); // переносим картинку внутрь bbox-обёртки
  });
}


function svbApplyTemplateTimings(videoId) {
    const timings = SVB_TEMPLATE_TIMINGS[videoId];
    if (!timings) return;
    
    // Обновить глобальный объект с новыми таймингами
    Object.keys(timings).forEach(key => {
        if (SVB_OVERLAY_WINDOWS && timings[key]) {
            SVB_OVERLAY_WINDOWS[key] = timings[key];
        }
    });
    
    // Пересчитать UI интервалов
    svbInitIntervalUi();
    svbBindIntervalUi();
}

// === СОХРАНЕНИЕ НАСТРОЕК (ADMIN) ===
// === ЛОГИКА СОХРАНЕНИЯ (ADMIN) ===
const saveBtn = document.getElementById('svb-save-settings-btn');
if (saveBtn) {
    saveBtn.addEventListener('click', () => {
        if (!confirm('Ви впевнені? Ці налаштування стануть дефолтними для всіх користувачів.')) return;

        // 1. Сохраняем текущее состояние инпутов в память
        ['child1','child2','parent1','parent2'].forEach(key => {
             if (typeof svbSaveInputsToScene === 'function') {
                 svbSaveInputsToScene(key, SVB_CURRENT_SCENE_INDEX[key]);
             }
        });

        // 2. Формируем JSON для сохранения
        const scenesConfig = {};
        
        ['child1','child2','parent1','parent2'].forEach(key => {
            scenesConfig[key] = [];
            const dataArr = SVB_SCENES_DATA[key] || [];
            
            // Важно: берем актуальные тайминги из SVB_OVERLAY_WINDOWS, 
            // так как пользователь мог их поменять в инпутах
            const timings = SVB_OVERLAY_WINDOWS[key] || [];
            
            dataArr.forEach((sceneItem, i) => {
                // Если есть тайминг в окнах, берем его, иначе старый
                const tPair = timings[i];
                const startStr = tPair ? svbSecondsToTS(tPair[0]) : sceneItem.start;
                const endStr   = tPair ? svbSecondsToTS(tPair[1]) : sceneItem.end;

                scenesConfig[key].push({
                    start: startStr,
                    end:   endStr,
                    label: sceneItem.label || `Сцена ${i+1}`,
                    // Сохраняем геометрию
                    x: sceneItem.x,
                    y: sceneItem.y,
                    scale: sceneItem.scale,
                    scale_x: sceneItem.scale_x, // <--- ДОБАВЛЕНО
                    scale_y: sceneItem.scale_y,
                    skew: sceneItem.skew,
                    skew_y: sceneItem.skew_y,
                    angle: sceneItem.angle,
                    radius: sceneItem.radius,
                    opacity: sceneItem.opacity,
                    glow: sceneItem.glow
                });
            });
        });

        // 3. Отправляем на сервер
        const fd = new FormData();
        fd.append('action', 'svb_save_config');
        fd.append('_svb_nonce', SVB_AJAX.nonce);
        fd.append('video_id', SVB_SELECTED_VIDEO_ID);
        fd.append('scenes', JSON.stringify(scenesConfig));

        const price1 = document.getElementById('svb-price-child-1');
        const price2 = document.getElementById('svb-price-child-2');
        const price3 = document.getElementById('svb-price-child-3');
        if (price1) fd.append('price_child_1', price1.value || '');
        if (price2) fd.append('price_child_2', price2.value || '');
        if (price3) fd.append('price_child_3', price3.value || '');

        const payloadSummary = {
            video_id: SVB_SELECTED_VIDEO_ID,
            scene_counts: Object.keys(scenesConfig).reduce((acc, key) => {
                acc[key] = Array.isArray(scenesConfig[key]) ? scenesConfig[key].length : 0;
                return acc;
            }, {}),
            prices: {
                price_child_1: price1 ? price1.value || '' : undefined,
                price_child_2: price2 ? price2.value || '' : undefined,
                price_child_3: price3 ? price3.value || '' : undefined,
            }
        };

        const logSaveConfigError = (status, url, data) => {
            try {
                console.groupCollapsed('[SVB][SAVE_CONFIG] ERROR');
                console.log('Status:', status);
                console.log('URL:', url);
                console.log('Payload summary:', payloadSummary);
                if (data) {
                    if (data.code) console.log('Response code:', data.code);
                    if (data.error_id) console.log('Error ID:', data.error_id);
                    if (data.debug) console.error('Debug:', data.debug);
                }
                console.groupEnd();
            } catch (_) {
                // no-op
            }
        };

        const oldText = saveBtn.textContent;
        const isDebugAdmin = !!(window.SVB_DATA && (window.SVB_DATA.is_admin || (window.SVB_DATA.payment && window.SVB_DATA.payment.is_admin)));
        const saveUrl = SVB_AJAX.url || '';

        saveBtn.disabled = true;
        saveBtn.textContent = 'Збереження...';

        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 25000); // FIX: Fail fast on stalled network

        fetch(saveUrl, { method:'POST', body:fd, credentials: 'same-origin', signal: controller.signal })
        .then(async (response) => {
            clearTimeout(timeoutId);
            const respUrl = response.url || saveUrl;
            const status = response.status;
            const text = await response.text();
            let json = null;
            try {
                json = JSON.parse(text);
            } catch (_) {
                // FIX: Fall back to raw text
            }

            if (isDebugAdmin) {
                console.group('[SVB][SAVE_CONFIG] Response');
                console.log('URL:', respUrl);
                console.log('Status:', status);
                console.log('Body (first 800 chars):', text ? text.slice(0, 800) : '');
                console.log('Parsed JSON:', json);
                console.groupEnd();
            }

            const payloadData = (json && json.data && typeof json.data === 'object') ? json.data : {};
            const payloadMessage = (payloadData && typeof payloadData.message !== 'undefined') ? payloadData.message : '';

            if (!response.ok) {
                const fallback = status ? `Сталася помилка на сервері (${status})` : 'Сталася помилка на сервері';
                if (json && json.success === false) {
                    logSaveConfigError(status, respUrl, payloadData);
                } else if (!json) {
                    logSaveConfigError(status, respUrl, null);
                }
                throw new Error(payloadMessage || fallback);
            }

            if (!json) {
                const fallback = status >= 500
                    ? 'Сталася помилка на сервері (500). Перевірте debug.log.'
                    : (status ? `Сталася помилка на сервері (${status})` : 'Некоректна відповідь сервера');
                logSaveConfigError(status, respUrl, null);
                throw new Error(payloadMessage || fallback);
            }

            if(json.success) {
                saveBtn.disabled = false;
                saveBtn.textContent = oldText;
                const okText = payloadMessage || json.data || 'Налаштування збережено';
                alert('✅ ' + okText);
                return;
            }

            const errText = payloadMessage || json.data || 'Помилка збереження';
            logSaveConfigError(status, respUrl, payloadData);
            throw new Error(errText);
        })
        .catch(err => {
            clearTimeout(timeoutId);
            saveBtn.disabled = false;
            saveBtn.textContent = oldText;
            const isAbort = err && err.name === 'AbortError';
            const msg = isAbort
                ? 'Час очікування вичерпано. Спробуйте ще раз.'
                : (err && err.message ? err.message : 'Мережева помилка');

            if (isDebugAdmin) {
                console.error('[SVB][SAVE_CONFIG][ERROR]', {
                    message: err && err.message,
                    name: err && err.name,
                    stack: err && err.stack,
                    url: saveUrl
                });
            }

            alert(msg);
        });
    });
}
// Функції відкриття/закриття попапа запиту імені
function svbOpenNamePopup() {
    document.getElementById('svb-name-popup').classList.add('active');
}
function svbCloseNamePopup(e, force = false) {
    const modal = document.getElementById('svb-name-popup');
    // Закриваємо якщо клік по фону або хрестику
    if (force || e.target === modal) {
        modal.classList.remove('active');
    }
}
// === ЗАПУСК ===
document.addEventListener('DOMContentLoaded', () => {
  console.log('🚀 SVB Init started (Final Fix)');

  svbFetchSessionDebug('init');

  const urlParams = new URLSearchParams(window.location.search);
  const isPaymentReturn = urlParams.has('svb_payment_return');
  const hasReturnToken = urlParams.has('token') || urlParams.has('svb_order') || urlParams.has('svb_token');
  const isResumeFromUrl = urlParams.has('svb_resume_order') || urlParams.has('svb_order') || urlParams.has('svb_token') || (isPaymentReturn && hasReturnToken);

  (async () => {
    let resumeHandled = false;
    if (isResumeFromUrl) {
      resumeHandled = await svbHandleResumeFromUrl(urlParams);
    }

    if (!resumeHandled && isPaymentReturn) {
      svbHandlePaymentReturnFlow(urlParams).catch(err => {
        console.log('[SVB RESUME][ERROR]', { message: err.message, stack: err.stack });
      });
      return;
    }

    if (!resumeHandled && !isPaymentReturn) {
      svbAutoResumeFromLocal({ isPaymentReturn: false });
    }
  })();

    // 1. Сначала отрисовываем выбор видео
    if (typeof svbRenderUI === 'function') {
        svbRenderUI();
    }

        const recoverModal = document.getElementById('svb-recover-modal');
        if (recoverModal) {
            recoverModal.addEventListener('click', (ev) => svbCloseRecoverModal(ev));
            const openBtn = document.getElementById('svb-recover-open');
            const newBtn = document.getElementById('svb-recover-new');
            if (openBtn) {
                openBtn.addEventListener('click', async (ev) => {
                    ev.preventDefault();
                    svbCloseRecoverModal(ev, true);
                    const resumeUrl = openBtn.dataset.resumeUrl || '';
                    try {
                      const u = new URL(resumeUrl, window.location.origin);
                      const orderId = parseInt(u.searchParams.get('order_id') || '0', 10);
                      const token = u.searchParams.get('token') || '';
                      if (orderId && token) {
                        const info = await svbOrderResumeInfoRequest(orderId, token);
                        svbUpdateState({ order_id: orderId, public_token: token });
                        if (info && info.download_url) {
                          await svbHandlePaidResume(orderId, token, info);
                          return;
                        }
                        if (info && info.can_regen) {
                          await svbHandlePaidResume(orderId, token, info);
                          return;
                        }
                      }
                    } catch (resumeErr) {
                      console.log('[SVB RESUME][ERROR]', { message: resumeErr.message, stack: resumeErr.stack });
                    }
                    if (resumeUrl) {
                      window.location = resumeUrl;
                    }
                });
            }
            if (newBtn) {
                newBtn.addEventListener('click', (ev) => {
                    ev.preventDefault();
                    svbCloseRecoverModal(ev, true);
                svbSetStep(2);
            });
        }
    }

    // 2. ВАЖНО: Сначала биндим переключатель детей, но НЕ вызываем обновление списков внутри него сразу
    //    Или просто привязываем события.
    const radios = document.querySelectorAll('input[name="child_count"]');
    radios.forEach(r => r.addEventListener('change', () => {
        // Обновляем глобальную переменную
        const checked = document.querySelector('input[name="child_count"]:checked');
        svbCurrentChildCount = checked ? parseInt(checked.value) : 1;
        
        // Управление видимостью полей
        const ageBlock = document.getElementById('svb-age-block');
        const field2 = document.querySelector('.field-child-2');
        const field3 = document.querySelector('.field-child-3');

        if (svbCurrentChildCount > 1) {
            if (ageBlock) ageBlock.style.display = 'none';
        } else {
            if (ageBlock) ageBlock.style.display = 'block';
        }
        if (field2) field2.style.display = (svbCurrentChildCount >= 2) ? 'block' : 'none';
        if (field3) field3.style.display = (svbCurrentChildCount >= 3) ? 'block' : 'none';

        // Перерисовываем списки аудио (фильтрация)
        svbPopulateSelects();
        
        // Перерисовываем выбор видео (доступные шаблоны)
        svbRenderUI();
    }));

    // 3. Инициализируем переменную счетчика детей ПЕРЕД заполнением
    const checked = document.querySelector('input[name="child_count"]:checked');
    svbCurrentChildCount = checked ? parseInt(checked.value) : 1;

    // 4. ТЕПЕРЬ заполняем списки (Аудио)
    svbPopulateSelects();

    // 5. Запускаем логику отображения полей (скрыть/показать поля для 2-3 детей)
    //    Имитируем событие change, но аккуратно
    const event = new Event('change');
    if(checked) checked.dispatchEvent(event); 

    // Биндим остальные элементы
    svbBindAudioPreview();
    svbBindPhotoInputs(); 
    svbEnsureWrappers();
    svbBindNumericControls();
    svbInitIntervalUi();
    svbBindIntervalUi();
    svbBindRealtimeControls();

    // Биндим поиск имен
    svbBindNameSuggestUniversal('name_text', 'svb-name-suggest', 'name_audio', 'svb-name-display-1', 'svb-name-play-1');
    svbBindNameSuggestUniversal('name_text_2', 'svb-name-suggest-2', 'name_audio_2', 'svb-name-display-2', 'svb-name-play-2');
    svbBindNameSuggestUniversal('name_text_3', 'svb-name-suggest-3', 'name_audio_3', 'svb-name-display-3', 'svb-name-play-3');

    // Логика попапа запроса имени
    const reqSubmit = document.getElementById('popup_req_submit');
    if (reqSubmit) {
        reqSubmit.addEventListener('click', () => {
            const nameVal = document.getElementById('popup_req_name').value.trim();
            const emailVal = document.getElementById('popup_req_email').value.trim();

            if (!nameVal || !emailVal) {
                alert('Будь ласка, заповніть обидва поля (Ім\'я та Email).');
                return;
            }

            const originalBtnText = reqSubmit.textContent;
            reqSubmit.disabled = true;
            reqSubmit.textContent = 'Відправка...';

            const fd = new FormData();
            fd.append('action', 'svb_request_name');
            fd.append('_svb_nonce', SVB_AJAX.nonce);
            fd.append('name_req', nameVal);
            fd.append('email_req', emailVal);

            fetch(SVB_AJAX.url, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        alert(res.data);
                        document.getElementById('svb-name-popup').classList.remove('active');
                        document.getElementById('popup_req_name').value = '';
                        document.getElementById('popup_req_email').value = '';
                    } else {
                        alert('Помилка: ' + (res.data || 'Unknown error'));
                    }
                })
                .catch(err => { alert('Помилка з\'єднання'); })
                .finally(() => {
                    reqSubmit.disabled = false;
                    reqSubmit.textContent = originalBtnText;
                });
        });
    }

    // Принудительно выбираем видео и тайминги
    setTimeout(() => {
        if (typeof SVB_SELECTED_VIDEO_ID !== 'undefined' && SVB_SELECTED_VIDEO_ID) {
            svbSelectVideoTemplate(SVB_SELECTED_VIDEO_ID);
        } else {
            svbSelectVideoTemplate('video1');
        }
        // Обновляем превью картинок
        ['child1', 'child2', 'parent1', 'parent2'].forEach(key => {
            svbUpdatePreviewTransform(key);
            svbDebugPrint(key);
        });
    }, 200);

});

async function svbHandlePaidResume(orderId, token, resumeInfo = {}) {
  const downloadUrl = resumeInfo.download_url || '';

  svbEnsureStateStructure();

  console.log('[SVB RETURN]', {
    start: true,
    chosen_order_id: orderId || null,
    is_payment_return: true,
    has_download_url: !!downloadUrl,
    resume_source: 'payment_return'
  });

  if (orderId && token) {
    svbUpdateState({ order_id: orderId, public_token: token, payment_status: 'success' });
  }

  svbSetStep(3);

  if (downloadUrl) {
    const probe = await svbProbeDownload(downloadUrl, { force: true });
    console.log('[SVB DOWNLOAD] probe after payment', { order_id: orderId || null, reason: probe.reason });
    if (probe.okForVideo) {
      svbHandleSuccessInternal(downloadUrl);
      return;
    }
    if (probe.reason && String(probe.reason).includes('no_file')) {
      console.log('[SVB DOWNLOAD] video file missing, re-triggering generation', { order_id: orderId || null });
    }
  }

  try {
    svbShowPaymentProcessing('Оплата підтверджена. Генеруємо відео…');
    if (resumeInfo && resumeInfo.form_data) {
      try {
        const current = svbLoadState() || {};
        const restored = Object.assign({}, current, {
          child_count: resumeInfo.form_data.child_count || current.child_count || 1,
          selected_video_id: resumeInfo.form_data.selected_video_id || current.selected_video_id || 'video1',
          formData: Object.assign({}, resumeInfo.form_data)
        });
        svbUpdateState(restored);
        if (typeof svbRestoreStep2State === 'function') {
          svbRestoreStep2State();
        }
      } catch (restoreErr) {
        console.log('[SVB RESUME][ERROR]', { message: restoreErr.message, stack: restoreErr.stack });
      }
    }

    if (typeof svbStartGenerate === 'function') {
      svbStartGenerate();
    }
  } catch (err) {
    console.log('[SVB RESUME][ERROR]', { message: err.message, stack: err.stack });
  }

  svbCloseRecoverModal({ target: document.getElementById('svb-recover-modal') }, true);
}
function svbDebugPrint(key) {
  const img = document.getElementById('img-' + key);
  if (!img) return;
  let dbg = document.getElementById('svb-dbg-' + key);
  if (!dbg) {
    dbg = document.createElement('div');
    dbg.id = 'svb-dbg-' + key;
    dbg.style.font = '12px/1.3 monospace';
    dbg.style.marginTop = '6px';
    img.closest('.svb-drop')?.appendChild(dbg);
  }
  const cs = getComputedStyle(img);
  dbg.textContent =
    `left:${cs.left} top:${cs.top} width:${cs.width} height:${cs.height} ` +
    `transform-origin:${cs.transformOrigin} transform:${cs.transform}`;
}

function svbDumpOverlayDebug(el, video, key, token){
  if(!el || !video) return;

  const targetEl = (el.parentElement && el.parentElement.classList.contains('svb-ovbox')) 
                   ? el.parentElement 
                   : el;

  const cs = getComputedStyle(targetEl);
  const r  = targetEl.getBoundingClientRect();
  const geom = svbComputeOverlayGeom(key);

  let angleDegCss = 0;
  const m = cs.transform.match(/matrix\(([^)]+)\)/);
  if (m) {
    const [a,b] = m[1].split(',').map(s=>parseFloat(s.trim()));
    angleDegCss = Math.atan2(b, a) * 180 / Math.PI;
  }

  const payload = {
    key,
    t: video.currentTime || 0,
    videoSize: { w: video.videoWidth, h: video.videoHeight },
    previewSize: { w: video.clientWidth, h: video.clientHeight },

    imgRectPx: {
      left: r.left + window.scrollX,
      top:  r.top  + window.scrollY,
      width: r.width,
      height: r.height
    },
    css: {
      transform: cs.transform,
      angleDegCss: Math.round(angleDegCss * 1000) / 1000
    },

    geom // всё, что посчитал svbComputeOverlayGeom
  };

  const fd = new FormData();
  fd.append('action','svb_dbg_push');
  fd.append('_svb_nonce', SVB_AJAX.nonce);
  fd.append('token', token || (window.svbJobToken||''));
  fd.append('payload', JSON.stringify(payload));
  fetch(SVB_AJAX.url, { method:'POST', body: fd }).then(()=>{});
}
// === МОБИЛЬНЫЙ СЛАЙДЕР (СКРИПТ-АДАПТАЦИЯ) ===
(function() {
    // 1. Создаем стили, которые перебивают inline-стили на мобилках
    const mobileStyle = document.createElement('style');
    mobileStyle.innerHTML = `
        @media (max-width: 768px) {
            #svb-video-selector {
                display: flex !important;       /* Перебиваем Grid */
                flex-wrap: nowrap !important;   /* Запрещаем перенос (лента) */
                overflow-x: auto !important;    /* Разрешаем скролл */
                scroll-snap-type: x mandatory;  /* Магнитное прилипание */
                grid-template-columns: none !important; /* Убираем колонки */
                gap: 16px !important;
                padding: 10px 4px 25px 4px !important; /* Отступ снизу */
                
                /* Скрываем скроллбар */
                scrollbar-width: none;
                -ms-overflow-style: none;
            }
            
            #svb-video-selector::-webkit-scrollbar {
                display: none;
            }

            .svb-video-option {
                flex: 0 0 100% !important;       /* Ширина карточки 85% экрана */
                width: 100% !important;
                max-width: 100% !important;
                scroll-snap-align: center;      /* Центровка при остановке */
                margin: 0 !important;
                transform: scale(0.96);         /* Чуть уменьшаем неактивные */
                transition: transform 0.3s ease, border-color 0.3s ease;
            }

            .svb-video-option.active {
                transform: scale(1);            /* Активная карточка крупнее */
                border-color: #FACC15 !important;
                box-shadow: 0 10px 25px rgba(250, 204, 21, 0.3) !important;
                outline: none !important;
            }
        }
    `;
    document.head.appendChild(mobileStyle);

    // 2. Логика авто-прокрутки к активному видео при загрузке
    document.addEventListener('DOMContentLoaded', () => {
        setTimeout(() => {
            const container = document.getElementById('svb-video-selector');
            const activeItem = container ? container.querySelector('.active') : null;
            
            // Если мы на мобильном и есть активный элемент
            if (window.innerWidth <= 768 && container && activeItem) {
                const scrollLeft = activeItem.offsetLeft - (container.clientWidth / 2) + (activeItem.clientWidth / 2);
                container.scrollTo({
                    left: scrollLeft,
                    behavior: 'smooth'
                });
            }
        }, 500); // Небольшая задержка, чтобы элементы успели отрисоваться
    });
})();
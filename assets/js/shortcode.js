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
// === УНИВЕРСАЛЬНЫЙ РЕНДЕР (ПК vs МОБИЛКА) ===
function svbRenderUI() {
    const container = document.getElementById('svb-dynamic-container');
    if (!container) return;

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
    if (!isSelectedValid && availableVideos.length > 0) {
        SVB_SELECTED_VIDEO_ID = availableVideos[0][0];
        const hInput = document.getElementById('selected_video_id');
        if(hInput) hInput.value = SVB_SELECTED_VIDEO_ID;
        svbSelectVideoTemplate(SVB_SELECTED_VIDEO_ID, false);
    }

    const isMobile = window.innerWidth <= 768;

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
    document.querySelectorAll('.svb-name-play').forEach(btn => {
        // Удаляем старые, чтобы не двоились (на всякий случай)
        const newBtn = btn.cloneNode(true); 
        btn.parentNode.replaceChild(newBtn, btn);
        
        newBtn.addEventListener('click', () => {
            const url = newBtn.dataset.audioUrl;
            if(!url) return;
            if(svbCurrentSampleAudio) {
                svbCurrentSampleAudio.pause();
                svbCurrentSampleAudio = null;
            }
            const a = new Audio(url);
            a.play();
            svbCurrentSampleAudio = a;
        });
    });
}

document.addEventListener('DOMContentLoaded', () => {
    console.log('🚀 SVB Init started (Final Fix Rebind)');

    // 1. Ініціалізуємо змінну кількості дітей перед усім іншим
    const checked = document.querySelector('input[name="child_count"]:checked');
    svbCurrentChildCount = checked ? parseInt(checked.value) : 1;

    // 2. Навішуємо обробник на перемикач кількості дітей
    // Це головний тригер: при зміні він викликає svbRenderUI, який перебудовує все
    const radios = document.querySelectorAll('input[name="child_count"]');
    radios.forEach(r => r.addEventListener('change', () => {
        const c = document.querySelector('input[name="child_count"]:checked');
        svbCurrentChildCount = c ? parseInt(c.value) : 1;
        
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
  $$('.svb-step').forEach(s=>s.classList.remove('active'));
  $(`.svb-step[data-step="${n}"]`).classList.add('active');
  for(let i=1;i<=3;i++){
    const dot = $(`#svb-dot-${i}`);
    dot.classList.toggle('active', i===n);
    dot.classList.toggle('muted', i!==n);
  }
  const titles = {1:'Крок 1 — Дані дитини', 2:'Крок 2 — Фото', 3:'Крок 3 — Підтвердження та отримання'};
  $('#svb-title').textContent = titles[n] || '';
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

        newInput.addEventListener('change', function(e) {
            const files = e.target.files;
            if (!files || !files.length) return;
            if (newInput.dataset.processing === 'true') return;

            const file = files[0];
            svbCurrentInput = newInput;
            svbCurrentKey = key;

            const reader = new FileReader();
            reader.onload = function(evt) {
                const modal = document.getElementById('svb-crop-modal');
                const image = document.getElementById('svb-crop-target');
                const headerTitle = modal.querySelector('h3');

                if (headerTitle) {
                    headerTitle.textContent = key.includes('child') ? "Фото дитини (обрізка):" : "Фото дорослого (обрізка):";
                }

                image.src = evt.target.result;
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
            };
            reader.readAsDataURL(file);
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
  // --- НОВА ЛОГІКА INPUT FILE ---
  ['child1', 'child2', 'parent1', 'parent2'].forEach(key => {
    const input = document.querySelector(`input[name="photo_${key}"]`);
    if(!input) return;

    input.addEventListener('change', function(e) {
        const files = e.target.files;
        if (!files || !files.length) return;

        // Якщо це вже оброблений файл (прапорець), нічого не робимо
        if (input.dataset.processing === 'true') {
            return;
        }

        const file = files[0];
        svbCurrentInput = input;
        svbCurrentKey = key;

        // 1. Читаємо файл
        const reader = new FileReader();
        reader.onload = function(evt) {
            // 2. Відкриваємо модалку
            const modal = document.getElementById('svb-crop-modal');
            const image = document.getElementById('svb-crop-target');
            const headerTitle = modal.querySelector('h3');
            
            // Змінюємо заголовок залежно від ключа (як ви просили)
            if (key.includes('child')) {
                headerTitle.textContent = "Add a photo of the child:";
            } else {
                headerTitle.textContent = "Add a photo of the parent:";
            }

            image.src = evt.target.result;
            modal.style.display = 'flex'; // Flex для центрування
            modal.classList.add('active');

            // 3. Ініціалізуємо Cropper
            if (svbCropper) svbCropper.destroy();
            
            // Налаштування Aspect Ratio
            // Для батьків (темне фото 3) та дітей (темне фото 2) ви хотіли певні розміри.
            // Зазвичай це портретний формат. 
            // Але оскільки ваш PHP код ріже під 709x709, 
            // безпечніше дати користувачу квадрат 1:1, або Free.
            // Я ставлю NaN (Free), щоб користувач сам вирішував, 
            // але можна розкоментувати aspectRatio: 9/16.
            
            svbCropper = new Cropper(image, {
                viewMode: 1,
                dragMode: 'move',
                autoCropArea: 0.9,
                restore: false,
                guides: true,
                center: true,
                highlight: false,
                cropBoxMovable: true,
cropBoxResizable: true,
                toggleDragModeOnDblclick: false,
                aspectRatio: ratio, 
            });
        };
        reader.readAsDataURL(file);
        
        // Скидаємо value, щоб change спрацював, навіть якщо вибрали той самий файл (але ми його підмінимо пізніше)
        input.value = ''; 
    });
  });

  // Логіка кнопки SAVE у модалці
  // ВИПРАВЛЕННЯ: Перейменували saveBtn -> cropModalSaveBtn, щоб уникнути конфлікту
  const cropModalSaveBtn = document.getElementById('svb-crop-save');
  
  if (cropModalSaveBtn) {
      // Видаляємо старі слухачі (щоб не дублювалися при перерендеренгу)
      const newBtn = cropModalSaveBtn.cloneNode(true);
      cropModalSaveBtn.parentNode.replaceChild(newBtn, cropModalSaveBtn);
      
      newBtn.addEventListener('click', () => {
          if (!svbCropper || !svbCurrentInput || !svbCurrentKey) return;

          // Отримуємо Canvas з обрізкою
          const canvas = svbCropper.getCroppedCanvas({
              maxWidth: 1920, 
              maxHeight: 1920,
              imageSmoothingEnabled: true,
              imageSmoothingQuality: 'high',
          });

          // Конвертуємо у WebP
          canvas.toBlob((blob) => {
              if (!blob) return;

              // 1. Створюємо новий файл
              const newFile = new File([blob], "cropped_image.webp", { type: "image/webp" });

              // 2. Підміняємо файл у input (через DataTransfer)
              const dataTransfer = new DataTransfer();
              dataTransfer.items.add(newFile);
              
              svbCurrentInput.files = dataTransfer.files;
              svbCurrentInput.dataset.processing = 'true'; // Прапорець, щоб не викликати loop
              svbCurrentInput.dataset.cropped = 'true';

              // 3. Оновлюємо прев'ю на сторінці
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

              // Закриваємо модалку
              if (typeof svbCloseCrop === 'function') svbCloseCrop();
              else {
                  document.getElementById('svb-crop-modal').style.display = 'none';
                  document.getElementById('svb-crop-modal').classList.remove('active');
                  if(svbCropper) svbCropper.destroy();
              }
              
              // Знімаємо прапорець через мить
              setTimeout(() => { 
                  if(svbCurrentInput) svbCurrentInput.dataset.processing = 'false'; 
              }, 100);

          }, 'image/webp', 0.9); // Якість WebP 0.9
      });
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
    facts:   pull('facts'),
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

$('#svb-next-1').addEventListener('click', ()=> svbSetStep(2));
$('#svb-back-2').addEventListener('click', ()=> svbSetStep(1));
let svbJobToken = null, svbVideoURL = null, svbGenerating = false;
let svbPollInterval = null; 
  $('#svb-next-2').addEventListener('click', ()=>{
    buildSoundMap();
    svbSetStep(3);
    $('#svb-status').textContent = 'Генеруємо відео… це може зайняти кілька хвилин';
    svbStartGenerate();
  });
$('#svb-back-3').addEventListener('click', ()=> {
  svbSetStep(2);
  if (svbPollInterval) clearInterval(svbPollInterval);
});
  $('#svb-finish').addEventListener('click', async ()=>{
    const email = $('#svb-email').value.trim();
    if(!email){ alert('Вкажіть email'); return; }
  if(!svbVideoURL){
    alert('Відео ще готується. Будь ласка, дочекайтесь повідомлення «Готово».');
    return;
  }
  const fd = new FormData();
  fd.append('action','svb_confirm');
  fd.append('_svb_nonce', SVB_AJAX.nonce);
  fd.append('email', email);
  fd.append('token', svbJobToken||'');
  fetch(SVB_AJAX.url, { method:'POST', body:fd })
    .then(r=>r.json()).then(data=>{
      if(data.success){
        const res = $('#svb-result');
        res.style.display='block';
        res.innerHTML = `<b>Готово!</b> <a href="${data.data.url}" download>Скачати відео</a>. Посилання дійсне 1 годину.`;
      } else {
        alert(data.data||'Помилка підтвердження');
      }
    });
  });
  function escapeHtml(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
  function svbToggleVideoOverlay(show){
    const overlay = $('#svb-video-overlay');
    if (!overlay) return;
    overlay.classList.toggle('svb-video-overlay--hidden', !show);
  }

  function svbUpdateVideoPercent(percent) {
    const percentBox = $('#svb-video-percent');
    if (percentBox) {
      percentBox.textContent = `Створення відео – ${percent}%`;
    }
  }
  async function svbStartGenerate() {
      if (svbGenerating) return;
      svbGenerating = true;
      svbToggleVideoOverlay(true);
      svbUpdateVideoPercent(0);
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
    
          if (data.success && data.data.token) {
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
            $('#svb-status').textContent = `Іде обробка... ${percent}%`;
          } else if (data.data.status === 'done') {
            clearInterval(svbPollInterval);
          svbHandleSuccess(data.data.url);
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
  function svbHandleSuccess(url) {
    svbGenerating = false;
    svbToggleVideoOverlay(false);
    svbVideoURL = url;
    svbUpdateVideoPercent(100);
    $('#svb-status').innerHTML = `✅ Відео зібрано. <a href="${url}" download>Скачати</a>`;
    const res = $('#svb-result');
    res.style.display = 'block';
  res.innerHTML = `<b>Готово!</b> <a href="${url}" download>Скачати відео</a>. Посилання дійсне 1 годину.`;
  $('#svb-finish').disabled = false;
  }
  function svbHandleError(data) {
    svbGenerating = false;
    svbToggleVideoOverlay(false);
    $('#svb-status').textContent = 'Сталася помилка при генерації відео';
  const res = $('#svb-result');
  if (res) {
    let msg, cmd = '', log = '', hint = '';

    if (typeof data === 'string') {
      msg = data;                 // ← показываем строку, если это строка
    } else {
      msg  = (data && data.msg)  || 'Unknown error';
      cmd  = (data && data.cmd)  || '';
      log  = (data && data.log)  || '';
      hint = (data && data.hint) || '';
    }

    res.style.display = 'block';
    res.innerHTML = `<details open>
       <summary><b>Деталі помилки</b></summary>
       <div style="margin-top:8px"><b>Msg:</b> ${escapeHtml(msg)}</div>
       ${cmd ? `<div><b>Cmd:</b> <code style="white-space:pre-wrap">${escapeHtml(cmd)}</code></div>` : ''}
       ${hint? `<div><b>Hint:</b> ${escapeHtml(hint)}</div>` : ''}
       <pre style="white-space:pre-wrap;max-height:260px;overflow:auto;margin-top:8px">${escapeHtml(String(log)).slice(0,8000)}</pre>
    </details>`;
  }
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
            console.log('Попытка включить кнопку для:', file);
            const fullItem = getAllNames().find(i => i.file === file);
            
            if (fullItem && fullItem.url) {
                console.log('Аудио найдено:', fullItem.url);
                playBtn.dataset.audioUrl = fullItem.url;
                playBtn.style.display = 'flex'; // Показываем кнопку
            } else {
                console.warn('Аудио НЕ найдено для файла:', file);
                // Попробуем собрать URL вручную, если в JSON его нет (fallback)
                // Это поможет, если структура каталога простая
                // Но лучше полагаться на данные из PHP
            }
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

        const oldText = saveBtn.textContent;
        saveBtn.disabled = true;
        saveBtn.textContent = 'Збереження...';

        fetch(SVB_AJAX.url, { method:'POST', body:fd })
        .then(r => r.json())
        .then(res => {
            saveBtn.disabled = false;
            saveBtn.textContent = oldText;
            if(res.success) {
                alert('✅ ' + res.data);
            } else {
                alert('❌ Помилка: ' + (res.data || 'Unknown error'));
            }
        })
        .catch(err => {
            saveBtn.disabled = false;
            saveBtn.textContent = oldText;
            alert('Мережева помилка');
            console.error(err);
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

    // 1. Сначала отрисовываем выбор видео
    if (typeof svbRenderUI === 'function') {
        svbRenderUI();
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



<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<style>
<?php if ( ! $is_admin ) : ?>
  .svb-admin-only, 
  [id^="svb-dbg-"] {
    display: none !important;
  }
<?php endif; ?>
  </style>
<div class="svb-wrap">
      <div class="svb-card">
        
        <div style="margin-bottom:15px; padding:10px 15px; background:#f0f8ff; border-radius:10px; font-size:13px; color:#333;">
            <?php echo wp_kses_post( $welcome_msg ); ?>
            <?php echo wp_kses_post( $video_ready_html ); ?>
        </div>
        <div id="svb-dynamic-container"></div>

        <div class="svb-header">
            <div class="svb-stepper">
                <span class="svb-dot active" id="svb-dot-1">1</span>
                <span class="svb-dot muted" id="svb-dot-2">2</span>
                <span class="svb-dot muted" id="svb-dot-3">3</span>
            </div>
            <h2 class="svb-title" id="svb-title">Крок 1 — Дані дитини</h2>
        </div>

        <form id="svb-form" enctype="multipart/form-data">
            <input type="hidden" name="selected_video_id" id="selected_video_id" value="<?php echo esc_attr($selected_video_id); ?>" />

      <input type="hidden" name="_svb_nonce" value="<?php echo esc_attr($nonce); ?>" />
      <input type="hidden" name="svb_segments" id="svb_segments" value="" />

      <section class="svb-step active" data-step="1">
        
        <div class="svb-field" style="text-align:center;">
    <label class="svb-label" style="margin-bottom:10px;">Для скількох дітей відео?</label>
    
    <div class="svb-child-count-wrap">
        <input type="radio" name="child_count" id="cc1" value="1" class="svb-child-radio" checked>
        <label for="cc1" class="svb-child-label">
            <span class="svb-c-text">1 дитина</span>
            <span class="svb-c-price">
                <s class="svb-c-old">350</s>
                <span class="svb-c-new">249 грн</span>
            </span>
        </label>
        
        <input type="radio" name="child_count" id="cc2" value="2" class="svb-child-radio">
        <label for="cc2" class="svb-child-label">
            <span class="svb-c-text">2 дитини</span>
            <span class="svb-c-price">
                <s class="svb-c-old">350</s>
                <span class="svb-c-new">249 грн</span>
            </span>
        </label>
        
        <input type="radio" name="child_count" id="cc3" value="3" class="svb-child-radio">
        <label for="cc3" class="svb-child-label">
            <span class="svb-c-text">3 дитини</span>
            <span class="svb-c-price">
                <s class="svb-c-old">350</s>
                <span class="svb-c-new">249 грн</span>
            </span>
        </label>
    </div>
</div>

        <div class="svb-grid cols-2">
          
          <div class="svb-names-row">
              <div class="svb-field svb-suggest">
                <label class="svb-label">Імʼя (Дитина 1)</label>
                <div class="svb-input-wrapper">
                    <input class="svb-input" type="text" name="name_text" placeholder="Почніть вводити ім'я..." autocomplete="off" />
                    <button class="svb-play svb-name-play" type="button" id="svb-name-play-1" title="Прослухати ім'я">▶</button>
                </div>
                <div id="svb-name-suggest"></div>
                <select name="name_audio" style="display:none;"></select> 
                <div style="margin-top:5px; font-size:12px; color:#666;" id="svb-name-display-1">Озвучка не обрана</div>
                
                <div class="svb-req-trigger-link" onclick="svbOpenNamePopup()">
    <span class="svb-req-text">✨ Не знайшли ім'я? <u>Натисніть тут</u></span>
    <span class="svb-req-plus">+</span>
</div>
              </div>

              <div class="svb-field svb-suggest field-child-2" style="display:none;">
                 <label class="svb-label">Імʼя (Дитина 2)</label>
                 <div class="svb-input-wrapper">
                     <input class="svb-input" type="text" name="name_text_2" placeholder="Ім'я другої дитини..." autocomplete="off" />
                     <button class="svb-play svb-name-play" type="button" id="svb-name-play-2" title="Прослухати ім'я">▶</button>
                 </div>
                 <div id="svb-name-suggest-2" class="svb-suggest-box"></div>
                 <select name="name_audio_2" style="display:none;"></select>
                 <div style="margin-top:5px; font-size:12px; color:#666;" id="svb-name-display-2">Озвучка не обрана</div>

              </div>

              <div class="svb-field svb-suggest field-child-3" style="display:none;">
                 <label class="svb-label">Імʼя (Дитина 3)</label>
                 <div class="svb-input-wrapper">
                     <input class="svb-input" type="text" name="name_text_3" placeholder="Ім'я третьої дитини..." autocomplete="off" />
                     <button class="svb-play svb-name-play" type="button" id="svb-name-play-3" title="Прослухати ім'я">▶</button>
                 </div>
                 <div id="svb-name-suggest-3" class="svb-suggest-box"></div>
                 <select name="name_audio_3" style="display:none;"></select>
                 <div style="margin-top:5px; font-size:12px; color:#666;" id="svb-name-display-3">Озвучка не обрана</div>

              </div>
          </div>
          
          <div class="svb-field" id="svb-age-block">
            <label class="svb-label">Вік</label>
            <div class="svb-audio-row">
              <select class="svb-select" name="age_audio" data-cat="age">
                  <option value="">Оберіть вік...</option>
              </select>
              <button class="svb-play" type="button" data-play="age" title="Прослухати">▶</button>
            </div>
          </div>

          <div class="svb-field">
            <label class="svb-label">Факти з життя</label>
            <div class="svb-audio-row">
              <select class="svb-select" name="facts_audio" data-cat="facts"></select>
              <button class="svb-play" type="button" data-play="facts" title="Прослухати">▶</button>
            </div>
          </div>

          <div class="svb-field">
            <label class="svb-label">Захоплення</label>
            <div class="svb-audio-row">
              <select class="svb-select" name="hobby_audio" data-cat="hobby"></select>
              <button class="svb-play" type="button" data-play="hobby" title="Прослухати">▶</button>
            </div>
          </div>

          <div class="svb-field">
            <label class="svb-label">За що похвалити</label>
            <div class="svb-audio-row">
              <select class="svb-select" name="praise_audio" data-cat="praise"></select>
              <button class="svb-play" type="button" data-play="praise" title="Прослухати">▶</button>
            </div>
          </div>

          <div class="svb-field">
            <label class="svb-label">Особливе прохання</label>
            <div class="svb-audio-row">
              <select class="svb-select" name="request_audio" data-cat="request"></select>
              <button class="svb-play" type="button" data-play="request" title="Прослухати">▶</button>
            </div>
          </div>

          <div class="svb-customer-cell">
              
              <div class="svb-customer-col">
                  <label class="svb-label">Ваше ім’я</label>
                  <input class="svb-input" type="text" name="customer_name" placeholder="Ваше ім’я" value="<?php echo esc_attr($order_data['customer_name'] ?? ''); ?>">
                  
                  <a href="#" class="svb-promo-link" onclick="return false;">
                      <span>🎁 Маєте промокод? <u>Натисніть тут</u></span>
                  </a>
              </div>

              <div class="svb-customer-col">
                  <label class="svb-label">Ваш e-mail</label>
                  <input class="svb-input" type="email" name="customer_email_step1" placeholder="E-mail" value="<?php echo esc_attr($order_data['customer_email'] ?? ''); ?>">
              </div>

          </div>
          

        <div class="svb-actions">
          <button class="svb-btn primary" type="button" id="svb-next-1">Далі &nbsp; &rarr;</button>
        </div>
      </section>

      <section class="svb-step" data-step="2">
        <div class="svb-photo-grid">
          
          <div class="svb-drop" data-photo="child1">
            <div class="svb-field">
              <span class="svb-label">Фото дитини 1</span>
              <input class="svb-input" type="file" name="photo_child1" accept="image/*" required>
            </div>
            
            <div class="svb-vid-preview" id="svb-vid-preview-child1">
              <video id="svb-video-child1" src="<?php echo esc_url($template_url); ?>" playsinline loop></video>
              <img id="img-child1" alt="Фото тут" />
            </div>
            
            <div class="svb-vid-seek-bar-container">
              <input
                type="range"
                class="svb-range svb-seek-bar"
                data-vid-ctrl="seek"
                data-key="child1"
                min="0"
                value="0"
                step="0.001"
              >
              <div style="margin-top: 4px; display: flex; align-items: center; gap: 6px; font-size: 12px;">
                <span>Час, мс:</span>
                <input
                  type="number"
                  class="svb-time-input"
                  data-key="child1"
                  value="0"
                  min="0"
                  step="1"
                  style="width: 90px; padding: 2px 4px; font-size: 12px;"
                >
              </div>
            </div>

            <div class="svb-vid-controls">
              <button type="button" class="svb-btn ghost" data-vid-ctrl="play" data-key="child1">► Play</button>
              <button type="button" class="svb-btn ghost" data-vid-ctrl="pause" data-key="child1" style="display:none;">❚❚ Pause</button>
              <div id="svb-vid-time-child1" class="svb-btn ghost">00:00 / 00:00</div> 
              
              <button type="button" class="svb-btn ghost" data-vid-ctrl="mute" data-key="child1">🔇 Mute</button>
              <button type="button" class="svb-btn ghost" data-vid-ctrl="unmute" data-key="child1" style="display:none;">🔈 Unmute</button>
              <input type="range" class="svb-range" data-vid-ctrl="volume" data-key="child1" min="0" max="1" step="0.05" value="0.8">
              <button type="button" class="svb-btn ghost" data-vid-ctrl="log" data-key="child1">📌 Log frame</button>
            </div>

            <div class="svb-note" style="margin-top: 4px;">
              <b>Керування:</b> Фокус на слайдерах. <b>Стрілки</b> (X/Y), <b>+ / -</b> (Scale), <b>[ / ]</b> (Radius), <b>, / .</b> (Angle).
            </div>
<div class="svb-admin-only">
<div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #f4c2c2; text-align: right;">
    <button type="button" id="svb-save-settings-btn" class="svb-btn primary" style="background: #2271b1; font-size: 13px;">
        💾 Зберегти налаштування (для всіх)
    </button>
</div>

    <div style="margin-bottom:10px; padding:6px; background:#eef; border-radius:6px; display:flex; align-items:center; gap:8px;">
    <strong>Налаштування сцени:</strong>
    <select class="svb-scene-select svb-input" data-key="child1" style="padding:4px; font-size:13px;">
        <option value="0">Сцена 1</option>
    </select>
    <button type="button" class="svb-btn ghost svb-scene-jump" data-key="child1" title="Перейти до часу сцени">👁</button>
</div>
            <div class="svb-controls" style="grid-template-columns: 1fr; gap: 12px;">
              <label>
                X
                <input
                  class="svb-val svb-val-input"
                  id="val-child1-x"
                  type="number"
                  value="785"
                  min="-1000"
                  max="2500"
                  step="5"
                  data-range-name="child1_x"
                />
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child1_x"
                  value="785"
                  min="-1000"
                  max="2500"
                  step="5"
                  data-val-id="val-child1-x"
                  data-key-up="ArrowRight"
                  data-key-down="ArrowLeft"
                />
              </label>

              <label>
                Y
                <input
                  class="svb-val svb-val-input"
                  id="val-child1-y"
                  type="number"
                  value="315"
                  min="-1000"
                  max="2000"
                  step="5"
                  data-range-name="child1_y"
                />
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child1_y"
                  value="315"
                  min="-1000"
                  max="2000"
                  step="5"
                  data-val-id="val-child1-y"
                  data-key-up="ArrowDown"
                  data-key-down="ArrowUp"
                />
              </label>

              <label>
                Scale
                <input
                  class="svb-val svb-val-input"
                  id="val-child1-scale"
                  type="number"
                  value="29"
                  min="10"
                  max="200"
                  step="1"
                  data-range-name="child1_scale"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child1_scale"
                  value="29"
                  min="10"
                  max="200"
                  step="1"
                  data-val-id="val-child1-scale"
                  data-key-up="="
                  data-key-down="-"
                />
              </label>
<label>
                Scale X
                <input
                  class="svb-val svb-val-input"
                  id="val-child1-scale-x"
                  type="number"
                  value="100"
                  min="10"
                  max="300"
                  step="1"
                  data-range-name="child1_scale_x"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child1_scale_x"
                  value="100"
                  min="10"
                  max="300"
                  step="1"
                  data-val-id="val-child1-scale-x"
                />
              </label>
              <label>
                Scale Y
                <input
                  class="svb-val svb-val-input"
                  id="val-child1-scale-y"
                  type="number"
                  value="100"
                  min="10"
                  max="200"
                  step="1"
                  data-range-name="child1_scale_y"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child1_scale_y"
                  value="100"
                  min="10"
                  max="200"
                  step="1"
                  data-val-id="val-child1-scale-y"
                />
              </label>

              <label>
                Skew X
                <input
                  class="svb-val svb-val-input"
                  id="val-child1-skew"
                  type="number"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-range-name="child1_skew"
                />
                °
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child1_skew"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-val-id="val-child1-skew"
                />
              </label>

              <label>
                Skew Y
                <input
                  class="svb-val svb-val-input"
                  id="val-child1-skew-y"
                  type="number"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-range-name="child1_skew_y"
                />
                °
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child1_skew_y"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-val-id="val-child1-skew-y"
                />
              </label>

              <label>
                Angle
                <input
                  class="svb-val svb-val-input"
                  id="val-child1-angle"
                  type="number"
                  value="4"
                  min="-45"
                  max="45"
                  step="1"
                  data-range-name="child1_angle"
                />
                °
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child1_angle"
                  value="4"
                  min="-45"
                  max="45"
                  step="1"
                  data-val-id="val-child1-angle"
                  data-key-up="."
                  data-key-down=","
                />
              </label>

              <label>
                Radius
                <input
                  class="svb-val svb-val-input"
                  id="val-child1-radius"
                  type="number"
                  value="30"
                  min="0"
                  max="200"
                  step="1"
                  data-range-name="child1_radius"
                />
                px
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child1_radius"
                  value="30"
                  min="0"
                  max="200"
                  step="1"
                  data-val-id="val-child1-radius"
                  data-key-up="]"
                  data-key-down="["
                />
              </label>

              <label>
                Прозорість:
                <input
                  class="svb-val svb-val-input"
                  id="val-child1-opacity"
                  type="number"
                  value="100"
                  min="30"
                  max="100"
                  step="1"
                  data-range-name="child1_opacity"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child1_opacity"
                  min="30"
                  max="100"
                  step="1"
                  value="100"
                  data-val-id="val-child1-opacity"
                />
              </label>

              <label>
                Світлі краї:
                <input
                  class="svb-val svb-val-input"
                  id="val-child1-glow"
                  type="number"
                  value="0"
                  min="0"
                  max="100"
                  step="1"
                  data-range-name="child1_glow"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child1_glow"
                  min="0"
                  max="100"
                  step="1"
                  value="0"
                  data-val-id="val-child1-glow"
                />
              </label>
              <label>
                Str L (Left)
                <input
                  class="svb-val svb-val-input"
                  id="val-child1-pleft"
                  type="number"
                  value="0"
                  min="-100"
                  max="100"
                  step="1"
                  data-range-name="child1_pleft"
                />
                px
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child1_pleft"
                  value="0"
                  min="-100"
                  max="100"
                  step="1"
                  data-val-id="val-child1-pleft"
                />
              </label>

              <label>
                Str R (Right)
                <input
                  class="svb-val svb-val-input"
                  id="val-child1-pright"
                  type="number"
                  value="0"
                  min="-100"
                  max="100"
                  step="1"
                  data-range-name="child1_pright"
                />
                px
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child1_pright"
                  value="0"
                  min="-100"
                  max="100"
                  step="1"
                  data-val-id="val-child1-pright"
                />
              </label>
            </div>

            <div class="svb-intervals" data-key="child1">
  <div class="svb-label">Інтервали накладання (MM:SS:CC)</div>
  <div class="svb-intervals-rows"></div>
  <div class="svb-note">Можна вказати кілька діапазонів. Формат: хвилини:секунди:соті.</div>
  <div style="margin-top:6px; display:flex; gap:6px; flex-wrap:wrap;">
    <button type="button" class="svb-btn ghost svb-int-add" data-key="child1">+ Додати інтервал</button>
    <button type="button" class="svb-btn ghost svb-int-reset" data-key="child1">Скинути за замовчуванням</button>
  </div>
</div>
</div>

          </div>

          <div class="svb-drop" data-photo="child2">
            <div class="svb-field">
              <span class="svb-label">Фото дитини 2</span>
              <input class="svb-input" type="file" name="photo_child2" accept="image/*" required>
            </div>

            <div class="svb-vid-preview" id="svb-vid-preview-child2">
              <video id="svb-video-child2" src="<?php echo esc_url($template_url); ?>" playsinline loop></video>
              <img id="img-child2" alt="Фото тут" />
            </div>

            <div class="svb-vid-seek-bar-container">
              <input
                type="range"
                class="svb-range svb-seek-bar"
                data-vid-ctrl="seek"
                data-key="child2"
                min="0"
                value="0"
                step="0.001"
              >
              <div style="margin-top: 4px; display: flex; align-items: center; gap: 6px; font-size: 12px;">
                <span>Час, мс:</span>
                <input
                  type="number"
                  class="svb-time-input"
                  data-key="child2"
                  value="0"
                  min="0"
                  step="1"
                  style="width: 90px; padding: 2px 4px; font-size: 12px;"
                >
              </div>
            </div>

            <div class="svb-vid-controls">
              <button type="button" class="svb-btn ghost" data-vid-ctrl="play" data-key="child2">► Play</button>
              <button type="button" class="svb-btn ghost" data-vid-ctrl="pause" data-key="child2" style="display:none;">❚❚ Pause</button>
              <div id="svb-vid-time-child2" class="svb-btn ghost">00:00 / 00:00</div>

              <button type="button" class="svb-btn ghost" data-vid-ctrl="mute" data-key="child2">🔇 Mute</button>
              <button type="button" class="svb-btn ghost" data-vid-ctrl="unmute" data-key="child2" style="display:none;">🔈 Unmute</button>
              <input type="range" class="svb-range" data-vid-ctrl="volume" data-key="child2" min="0" max="1" step="0.05" value="0.8">
              <button type="button" class="svb-btn ghost" data-vid-ctrl="log" data-key="child2">📌 Log frame</button>
            </div>

            <div class="svb-note" style="margin-top: 4px;">
              <b>Керування:</b> Фокус на слайдерах. <b>Стрілки</b> (X/Y), <b>+ / -</b> (Scale), <b>[ / ]</b> (Radius), <b>, / .</b> (Angle).
            </div>
<div class="svb-admin-only">
    <div style="margin-bottom:10px; padding:6px; background:#eef; border-radius:6px; display:flex; align-items:center; gap:8px;">
    <strong>Налаштування сцени:</strong>
    <select class="svb-scene-select svb-input" data-key="child2" style="padding:4px; font-size:13px;">
        <option value="0">Сцена 1</option>
    </select>
    <button type="button" class="svb-btn ghost svb-scene-jump" data-key="child2" title="Перейти до часу сцени">👁</button>
</div>
            <div class="svb-controls" style="grid-template-columns: 1fr; gap: 12px;">
              <label>
                X
                <input
                  class="svb-val svb-val-input"
                  id="val-child2-x"
                  type="number"
                  value="1156"
                  min="-1000"
                  max="2500"
                  step="5"
                  data-range-name="child2_x"
                />
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child2_x"
                  value="1156"
                  min="-1000"
                  max="2500"
                  step="5"
                  data-val-id="val-child2-x"
                  data-key-up="ArrowRight"
                  data-key-down="ArrowLeft"
                />
              </label>

              <label>
                Y
                <input
                  class="svb-val svb-val-input"
                  id="val-child2-y"
                  type="number"
                  value="250"
                  min="-1000"
                  max="2000"
                  step="5"
                  data-range-name="child2_y"
                />
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child2_y"
                  value="250"
                  min="-1000"
                  max="2000"
                  step="5"
                  data-val-id="val-child2-y"
                  data-key-up="ArrowDown"
                  data-key-down="ArrowUp"
                />
              </label>

              <label>
                Scale
                <input
                  class="svb-val svb-val-input"
                  id="val-child2-scale"
                  type="number"
                  value="33"
                  min="10"
                  max="200"
                  step="1"
                  data-range-name="child2_scale"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child2_scale"
                  value="33"
                  min="10"
                  max="200"
                  step="1"
                  data-val-id="val-child2-scale"
                  data-key-up="="
                  data-key-down="-"
                />
              </label>
<label>
                Scale X
                <input
                  class="svb-val svb-val-input"
                  id="val-child2-scale-x"
                  type="number"
                  value="100"
                  min="10"
                  max="300"
                  step="1"
                  data-range-name="child2_scale_x"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child2_scale_x"
                  value="100"
                  min="10"
                  max="300"
                  step="1"
                  data-val-id="val-child2-scale-x"
                />
              </label>
              <label>
                Scale Y
                <input
                  class="svb-val svb-val-input"
                  id="val-child2-scale-y"
                  type="number"
                  value="100"
                  min="10"
                  max="200"
                  step="1"
                  data-range-name="child2_scale_y"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child2_scale_y"
                  value="100"
                  min="10"
                  max="200"
                  step="1"
                  data-val-id="val-child2-scale-y"
                />
              </label>

              <label>
                Skew X
                <input
                  class="svb-val svb-val-input"
                  id="val-child2-skew"
                  type="number"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-range-name="child2_skew"
                />
                °
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child2_skew"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-val-id="val-child2-skew"
                />
              </label>

              <label>
                Skew Y
                <input
                  class="svb-val svb-val-input"
                  id="val-child2-skew-y"
                  type="number"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-range-name="child2_skew_y"
                />
                °
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child2_skew_y"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-val-id="val-child2-skew-y"
                />
              </label>

              <label>
                Angle
                <input
                  class="svb-val svb-val-input"
                  id="val-child2-angle"
                  type="number"
                  value="10"
                  min="-45"
                  max="45"
                  step="1"
                  data-range-name="child2_angle"
                />
                °
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child2_angle"
                  value="10"
                  min="-45"
                  max="45"
                  step="1"
                  data-val-id="val-child2-angle"
                  data-key-up="."
                  data-key-down=","
                />
              </label>

              <label>
                Radius
                <input
                  class="svb-val svb-val-input"
                  id="val-child2-radius"
                  type="number"
                  value="0"
                  min="0"
                  max="200"
                  step="1"
                  data-range-name="child2_radius"
                />
                px
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child2_radius"
                  value="0"
                  min="0"
                  max="200"
                  step="1"
                  data-val-id="val-child2-radius"
                  data-key-up="]"
                  data-key-down="["
                />
              </label>

              <label>
                Прозорість:
                <input
                  class="svb-val svb-val-input"
                  id="val-child2-opacity"
                  type="number"
                  value="100"
                  min="30"
                  max="100"
                  step="1"
                  data-range-name="child2_opacity"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child2_opacity"
                  min="30"
                  max="100"
                  step="1"
                  value="100"
                  data-val-id="val-child2-opacity"
                />
              </label>

              <label>
                Світлі краї:
                <input
                  class="svb-val svb-val-input"
                  id="val-child2-glow"
                  type="number"
                  value="0"
                  min="0"
                  max="100"
                  step="1"
                  data-range-name="child2_glow"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child2_glow"
                  min="0"
                  max="100"
                  step="1"
                  value="0"
                  data-val-id="val-child2-glow"
                />
              </label>
              <label>
                Str L (Left)
                <input
                  class="svb-val svb-val-input"
                  id="val-child2-pleft"
                  type="number"
                  value="0"
                  min="-100"
                  max="100"
                  step="1"
                  data-range-name="child2_pleft"
                />
                px
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child2_pleft"
                  value="0"
                  min="-100"
                  max="100"
                  step="1"
                  data-val-id="val-child2-pleft"
                />
              </label>

              <label>
                Str R (Right)
                <input
                  class="svb-val svb-val-input"
                  id="val-child2-pright"
                  type="number"
                  value="0"
                  min="-100"
                  max="100"
                  step="1"
                  data-range-name="child2_pright"
                />
                px
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="child2_pright"
                  value="0"
                  min="-100"
                  max="100"
                  step="1"
                  data-val-id="val-child2-pright"
                />
              </label>
            </div>

            <div class="svb-intervals" data-key="child2">
  <div class="svb-label">Інтервали накладання (MM:SS:CC)</div>
  <div class="svb-intervals-rows"></div>
  <div class="svb-note">За замовчуванням: сцена дитини в середині відео.</div>
  <div style="margin-top:6px; display:flex; gap:6px; flex-wrap:wrap;">
    <button type="button" class="svb-btn ghost svb-int-add" data-key="child2">+ Додати інтервал</button>
    <button type="button" class="svb-btn ghost svb-int-reset" data-key="child2">Скинути за замовчуванням</button>
  </div>
</div>
</div>

          </div>

          <div class="svb-drop" data-photo="parent1">
            <div class="svb-field">
              <span class="svb-label">Фото батька</span>
              <input class="svb-input" type="file" name="photo_parent1" accept="image/*" required>
            </div>

            <div class="svb-vid-preview" id="svb-vid-preview-parent1">
              <video id="svb-video-parent1" src="<?php echo esc_url($template_url); ?>" playsinline loop></video>
              <img id="img-parent1" alt="Фото тут" />
            </div>

            <div class="svb-vid-seek-bar-container">
              <input
                type="range"
                class="svb-range svb-seek-bar"
                data-vid-ctrl="seek"
                data-key="parent1"
                min="0"
                value="0"
                step="0.001"
              >
              <div style="margin-top: 4px; display: flex; align-items: center; gap: 6px; font-size: 12px;">
                <span>Час, мс:</span>
                <input
                  type="number"
                  class="svb-time-input"
                  data-key="parent1"
                  value="0"
                  min="0"
                  step="1"
                  style="width: 90px; padding: 2px 4px; font-size: 12px;"
                >
              </div>
            </div>

            <div class="svb-vid-controls">
              <button type="button" class="svb-btn ghost" data-vid-ctrl="play" data-key="parent1">► Play</button>
              <button type="button" class="svb-btn ghost" data-vid-ctrl="pause" data-key="parent1" style="display:none;">❚❚ Pause</button>
              <div id="svb-vid-time-parent1" class="svb-btn ghost">00:00 / 00:00</div>

              <button type="button" class="svb-btn ghost" data-vid-ctrl="mute" data-key="parent1">🔇 Mute</button>
              <button type="button" class="svb-btn ghost" data-vid-ctrl="unmute" data-key="parent1" style="display:none;">🔈 Unmute</button>
              <input type="range" class="svb-range" data-vid-ctrl="volume" data-key="parent1" min="0" max="1" step="0.05" value="0.8">
              <button type="button" class="svb-btn ghost" data-vid-ctrl="log" data-key="parent1">📌 Log frame</button>
            </div>

            <div class="svb-note" style="margin-top: 4px;">
              <b>Керування:</b> Фокус на слайдерах. <b>Стрілки</b> (X/Y), <b>+ / -</b> (Scale), <b>[ / ]</b> (Radius), <b>, / .</b> (Angle).
            </div>
<div class="svb-admin-only">
    <div style="margin-bottom:10px; padding:6px; background:#eef; border-radius:6px; display:flex; align-items:center; gap:8px;">
    <strong>Налаштування сцени:</strong>
    <select class="svb-scene-select svb-input" data-key="parent1" style="padding:4px; font-size:13px;">
        <option value="0">Сцена 1</option>
    </select>
    <button type="button" class="svb-btn ghost svb-scene-jump" data-key="parent1" title="Перейти до часу сцени">👁</button>
</div>
            <div class="svb-controls" style="grid-template-columns: 1fr; gap: 12px;">
              <label>
                X
                <input
                  class="svb-val svb-val-input"
                  id="val-parent1-x"
                  type="number"
                  value="166"
                  min="-1000"
                  max="2500"
                  step="5"
                  data-range-name="parent1_x"
                />
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent1_x"
                  value="166"
                  min="-1000"
                  max="2500"
                  step="5"
                  data-val-id="val-parent1-x"
                  data-key-up="ArrowRight"
                  data-key-down="ArrowLeft"
                />
              </label>

              <label>
                Y
                <input
                  class="svb-val svb-val-input"
                  id="val-parent1-y"
                  type="number"
                  value="0"
                  min="-1000"
                  max="2000"
                  step="5"
                  data-range-name="parent1_y"
                />
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent1_y"
                  value="0"
                  min="-1000"
                  max="2000"
                  step="5"
                  data-val-id="val-parent1-y"
                  data-key-up="ArrowDown"
                  data-key-down="ArrowUp"
                />
              </label>

              <label>
                Scale
                <input
                  class="svb-val svb-val-input"
                  id="val-parent1-scale"
                  type="number"
                  value="75"
                  min="10"
                  max="200"
                  step="1"
                  data-range-name="parent1_scale"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent1_scale"
                  value="75"
                  min="10"
                  max="200"
                  step="1"
                  data-val-id="val-parent1-scale"
                  data-key-up="="
                  data-key-down="-"
                />
              </label>
<label>
                Scale X
                <input
                  class="svb-val svb-val-input"
                  id="val-parent1-scale-x"
                  type="number"
                  value="100"
                  min="10"
                  max="300"
                  step="1"
                  data-range-name="parent1_scale_x"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent1_scale_x"
                  value="100"
                  min="10"
                  max="300"
                  step="1"
                  data-val-id="val-parent1-scale-x"
                />
              </label>
              <label>
                Scale Y
                <input
                  class="svb-val svb-val-input"
                  id="val-parent1-scale-y"
                  type="number"
                  value="100"
                  min="10"
                  max="200"
                  step="1"
                  data-range-name="parent1_scale_y"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent1_scale_y"
                  value="100"
                  min="10"
                  max="200"
                  step="1"
                  data-val-id="val-parent1-scale-y"
                />
              </label>

              <label>
                Skew X
                <input
                  class="svb-val svb-val-input"
                  id="val-parent1-skew"
                  type="number"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-range-name="parent1_skew"
                />
                °
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent1_skew"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-val-id="val-parent1-skew"
                />
              </label>

              <label>
                Skew Y
                <input
                  class="svb-val svb-val-input"
                  id="val-parent1-skew-y"
                  type="number"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-range-name="parent1_skew_y"
                />
                °
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent1_skew_y"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-val-id="val-parent1-skew-y"
                />
              </label>

              <label>
                Angle
                <input
                  class="svb-val svb-val-input"
                  id="val-parent1-angle"
                  type="number"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-range-name="parent1_angle"
                />
                °
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent1_angle"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-val-id="val-parent1-angle"
                  data-key-up="."
                  data-key-down=","
                />
              </label>

              <label>
                Radius
                <input
                  class="svb-val svb-val-input"
                  id="val-parent1-radius"
                  type="number"
                  value="0"
                  min="0"
                  max="200"
                  step="1"
                  data-range-name="parent1_radius"
                />
                px
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent1_radius"
                  value="0"
                  min="0"
                  max="200"
                  step="1"
                  data-val-id="val-parent1-radius"
                  data-key-up="]"
                  data-key-down="["
                />
              </label>

              <label>
                Прозорість:
                <input
                  class="svb-val svb-val-input"
                  id="val-parent1-opacity"
                  type="number"
                  value="100"
                  min="30"
                  max="100"
                  step="1"
                  data-range-name="parent1_opacity"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent1_opacity"
                  min="30"
                  max="100"
                  step="1"
                  value="100"
                  data-val-id="val-parent1-opacity"
                />
              </label>

              <label>
                Світлі краї:
                <input
                  class="svb-val svb-val-input"
                  id="val-parent1-glow"
                  type="number"
                  value="0"
                  min="0"
                  max="100"
                  step="1"
                  data-range-name="parent1_glow"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent1_glow"
                  min="0"
                  max="100"
                  step="1"
                  value="0"
                  data-val-id="val-parent1-glow"
                />
              </label>
              <label>
                Str L (Left)
                <input
                  class="svb-val svb-val-input"
                  id="val-parent1-pleft"
                  type="number"
                  value="0"
                  min="-100"
                  max="100"
                  step="1"
                  data-range-name="parent1_pleft"
                />
                px
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent1_pleft"
                  value="0"
                  min="-100"
                  max="100"
                  step="1"
                  data-val-id="val-parent1-pleft"
                />
              </label>

              <label>
                Str R (Right)
                <input
                  class="svb-val svb-val-input"
                  id="val-parent1-pright"
                  type="number"
                  value="0"
                  min="-100"
                  max="100"
                  step="1"
                  data-range-name="parent1_pright"
                />
                px
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent1_pright"
                  value="0"
                  min="-100"
                  max="100"
                  step="1"
                  data-val-id="val-parent1-pright"
                />
              </label>
            </div>

            <div class="svb-intervals" data-key="parents">
  <div class="svb-label">Інтервали для обох батьків (MM:SS:CC)</div>
  <div class="svb-intervals-rows"></div>
  <div class="svb-note">Ці інтервали застосовуються одночасно до фото батька і матері.</div>
  <div style="margin-top:6px; display:flex; gap:6px; flex-wrap:wrap;">
    <button type="button" class="svb-btn ghost svb-int-add" data-key="parents">+ Додати інтервал</button>
    <button type="button" class="svb-btn ghost svb-int-reset" data-key="parents">Скинути за замовчуванням</button>
  </div>
</div>
</div>
          </div>

          <div class="svb-drop" data-photo="parent2">
            <div class="svb-field">
              <span class="svb-label">Фото матері</span>
              <input class="svb-input" type="file" name="photo_parent2" accept="image/*" required>
            </div>

            <div class="svb-vid-preview" id="svb-vid-preview-parent2">
              <video id="svb-video-parent2" src="<?php echo esc_url($template_url); ?>" playsinline loop></video>
              <img id="img-parent2" alt="Фото тут" />
            </div>

            <div class="svb-vid-seek-bar-container">
              <input
                type="range"
                class="svb-range svb-seek-bar"
                data-vid-ctrl="seek"
                data-key="parent2"
                min="0"
                value="0"
                step="0.001"
              >
              <div style="margin-top: 4px; display: flex; align-items: center; gap: 6px; font-size: 12px;">
                <span>Час, мс:</span>
                <input
                  type="number"
                  class="svb-time-input"
                  data-key="parent2"
                  value="0"
                  min="0"
                  step="1"
                  style="width: 90px; padding: 2px 4px; font-size: 12px;"
                >
              </div>
            </div>

            <div class="svb-vid-controls">
              <button type="button" class="svb-btn ghost" data-vid-ctrl="play" data-key="parent2">► Play</button>
              <button type="button" class="svb-btn ghost" data-vid-ctrl="pause" data-key="parent2" style="display:none;">❚❚ Pause</button>
              <div id="svb-vid-time-parent2" class="svb-btn ghost">00:00 / 00:00</div>

              <button type="button" class="svb-btn ghost" data-vid-ctrl="mute" data-key="parent2">🔇 Mute</button>
              <button type="button" class="svb-btn ghost" data-vid-ctrl="unmute" data-key="parent2" style="display:none;">🔈 Unmute</button>
              <input type="range" class="svb-range" data-vid-ctrl="volume" data-key="parent2" min="0" max="1" step="0.05" value="0.8">
              <button type="button" class="svb-btn ghost" data-vid-ctrl="log" data-key="parent2">📌 Log frame</button>
            </div>

            <div class="svb-note" style="margin-top: 4px;">
              <b>Керування:</b> Фокус на слайдерах. <b>Стрілки</b> (X/Y), <b>+ / -</b> (Scale), <b>[ / ]</b> (Radius), <b>, / .</b> (Angle).
            </div>
<div class="svb-admin-only">
    <div style="margin-bottom:10px; padding:6px; background:#eef; border-radius:6px; display:flex; align-items:center; gap:8px;">
    <strong>Налаштування сцени:</strong>
    <select class="svb-scene-select svb-input" data-key="parent2" style="padding:4px; font-size:13px;">
        <option value="0">Сцена 1</option>
    </select>
    <button type="button" class="svb-btn ghost svb-scene-jump" data-key="parent2" title="Перейти до часу сцени">👁</button>
</div>
            <div class="svb-controls" style="grid-template-columns: 1fr; gap: 12px;">
              <label>
                X
                <input
                  class="svb-val svb-val-input"
                  id="val-parent2-x"
                  type="number"
                  value="166"
                  min="-1000"
                  max="2500"
                  step="5"
                  data-range-name="parent2_x"
                />
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent2_x"
                  value="166"
                  min="-1000"
                  max="2500"
                  step="5"
                  data-val-id="val-parent2-x"
                  data-key-up="ArrowRight"
                  data-key-down="ArrowLeft"
                />
              </label>

              <label>
                Y
                <input
                  class="svb-val svb-val-input"
                  id="val-parent2-y"
                  type="number"
                  value="0"
                  min="-1000"
                  max="2000"
                  step="5"
                  data-range-name="parent2_y"
                />
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent2_y"
                  value="0"
                  min="-1000"
                  max="2000"
                  step="5"
                  data-val-id="val-parent2-y"
                  data-key-up="ArrowDown"
                  data-key-down="ArrowUp"
                />
              </label>

              <label>
                Scale
                <input
                  class="svb-val svb-val-input"
                  id="val-parent2-scale"
                  type="number"
                  value="75"
                  min="10"
                  max="200"
                  step="1"
                  data-range-name="parent2_scale"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent2_scale"
                  value="75"
                  min="10"
                  max="200"
                  step="1"
                  data-val-id="val-parent2-scale"
                  data-key-up="="
                  data-key-down="-"
                />
              </label>
<label>
                Scale X
                <input
                  class="svb-val svb-val-input"
                  id="val-parent2-scale-x"
                  type="number"
                  value="100"
                  min="10"
                  max="300"
                  step="1"
                  data-range-name="parent2_scale_x"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent2_scale_x"
                  value="100"
                  min="10"
                  max="300"
                  step="1"
                  data-val-id="val-parent2-scale-x"
                />
              </label>
              <label>
                Scale Y
                <input
                  class="svb-val svb-val-input"
                  id="val-parent2-scale-y"
                  type="number"
                  value="100"
                  min="10"
                  max="200"
                  step="1"
                  data-range-name="parent2_scale_y"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent2_scale_y"
                  value="100"
                  min="10"
                  max="200"
                  step="1"
                  data-val-id="val-parent2-scale-y"
                />
              </label>

              <label>
                Skew X
                <input
                  class="svb-val svb-val-input"
                  id="val-parent2-skew"
                  type="number"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-range-name="parent2_skew"
                />
                °
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent2_skew"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-val-id="val-parent2-skew"
                />
              </label>

              <label>
                Skew Y
                <input
                  class="svb-val svb-val-input"
                  id="val-parent2-skew-y"
                  type="number"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-range-name="parent2_skew_y"
                />
                °
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent2_skew_y"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-val-id="val-parent2-skew-y"
                />
              </label>

              <label>
                Angle
                <input
                  class="svb-val svb-val-input"
                  id="val-parent2-angle"
                  type="number"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-range-name="parent2_angle"
                />
                °
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent2_angle"
                  value="0"
                  min="-45"
                  max="45"
                  step="1"
                  data-val-id="val-parent2-angle"
                  data-key-up="."
                  data-key-down=","
                />
              </label>

              <label>
                Radius
                <input
                  class="svb-val svb-val-input"
                  id="val-parent2-radius"
                  type="number"
                  value="0"
                  min="0"
                  max="200"
                  step="1"
                  data-range-name="parent2_radius"
                />
                px
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent2_radius"
                  value="0"
                  min="0"
                  max="200"
                  step="1"
                  data-val-id="val-parent2-radius"
                  data-key-up="]"
                  data-key-down="["
                />
              </label>

              <label>
                Прозорість:
                <input
                  class="svb-val svb-val-input"
                  id="val-parent2-opacity"
                  type="number"
                  value="100"
                  min="30"
                  max="100"
                  step="1"
                  data-range-name="parent2_opacity"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent2_opacity"
                  min="30"
                  max="100"
                  step="1"
                  value="100"
                  data-val-id="val-parent2-opacity"
                />
              </label>

              <label>
                Світлі краї:
                <input
                  class="svb-val svb-val-input"
                  id="val-parent2-glow"
                  type="number"
                  value="0"
                  min="0"
                  max="100"
                  step="1"
                  data-range-name="parent2_glow"
                />
                %
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent2_glow"
                  min="0"
                  max="100"
                  step="1"
                  value="0"
                  data-val-id="val-parent2-glow"
                />
              </label>
              <label>
                Str L (Left)
                <input
                  class="svb-val svb-val-input"
                  id="val-parent2-pleft"
                  type="number"
                  value="0"
                  min="-100"
                  max="100"
                  step="1"
                  data-range-name="parent2_pleft"
                />
                px
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent2_pleft"
                  value="0"
                  min="-100"
                  max="100"
                  step="1"
                  data-val-id="val-parent2-pleft"
                />
              </label>

              <label>
                Str R (Right)
                <input
                  class="svb-val svb-val-input"
                  id="val-parent2-pright"
                  type="number"
                  value="0"
                  min="-100"
                  max="100"
                  step="1"
                  data-range-name="parent2_pright"
                />
                px
                <input
                  class="svb-range svb-key-control"
                  type="range"
                  name="parent2_pright"
                  value="0"
                  min="-100"
                  max="100"
                  step="1"
                  data-val-id="val-parent2-pright"
                />
              </label>
            </div>

            <div class="svb-intervals" data-key="parents">
  <div class="svb-label">Інтервали для обох батьків (MM:SS:CC)</div>
  <div class="svb-intervals-rows"></div>
  <div class="svb-note">Ці інтервали застосовуються одночасно до фото батька і матері.</div>
  <div style="margin-top:6px; display:flex; gap:6px; flex-wrap:wrap;">
    <button type="button" class="svb-btn ghost svb-int-add" data-key="parents">+ Додати інтервал</button>
    <button type="button" class="svb-btn ghost svb-int-reset" data-key="parents">Скинути за замовчуванням</button>
  </div>
</div>
</div>
          </div>

          </div>

        <div class="svb-field svb-admin-only" style="margin: 10px 0;">
          <label class="svb-label" style="display:flex; align-items:center; gap:8px;">
            <input type="checkbox" id="svb_payment_toggle" checked />
            <span>Оплата включена (admin)</span>
          </label>
          <div style="font-size:12px; color:#555;">Вимикайте тільки для тестів або службової підтримки.</div>
        </div>

        <div class="svb-actions">
          <button class="svb-btn ghost" type="button" id="svb-back-2">Назад</button>
          <button class="svb-btn primary" type="button" id="svb-next-2">Далі &nbsp; &rarr;</button>
        </div>

          <div id="svb-payment-error" class="svb-payment-error" style="display:none; margin-top:12px; padding:12px; background:#fff3cd; border:1px solid #ffeeba; border-radius:8px;">
          <div class="svb-payment-error__text" style="margin-bottom:8px; color:#8a6d3b;">Оплата неуспішна. Генерація відео не буде виконана.</div>
          <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <button type="button" class="svb-btn primary" id="svb-payment-retry">Повторити оплату</button>
            <button type="button" class="svb-btn ghost" id="svb-payment-back">Назад</button>
          </div>
        </div>
      </section>

      <section class="svb-step svb-step--video" data-step="3">

        <div class="svb-video-page">
          <h2 class="svb-video-page__title">Ваше відео</h2>

          <div class="svb-video-card">
            <!-- LEFT -->
            <aside class="svb-video-left">

              <div class="svb-video-order">
                <?php echo wp_kses_post( $welcome_msg ); ?>
                <?php echo wp_kses_post( $video_ready_html ); ?>
              </div>

              <div class="svb-video-box">
                <div class="svb-video-box__title">📲 Завантажте відео на телефон чи комп’ютер.</div>
                <div class="svb-video-box__text">
                  Перегляд і посилання будуть активні всього кілька годин, але при необхідності ви зможете заново створити ваше відео
                  вказавши Ваш номер замовлення або E-mail.
                </div>
              </div>

              <div class="svb-video-box svb-video-box--small">
                <div class="svb-video-box__text svb-video-box__text--italic">
                  Якщо виникли технічні проблеми з відео або помилково вказали не вірні дані
                  <a class="svb-video-link" href="#" rel="nofollow">натисніть тут</a>.
                </div>
              </div>

              <div class="svb-video-share">
                <div class="svb-video-share__title">Поділитись відео</div>
                <div class="svb-video-share__icons" aria-label="Share buttons">
                  <!-- тут свои иконки/ссылки -->
                </div>
              </div>

              <!-- email оставляем, чтобы не ломать логику -->
              <div class="svb-video-email">
                <label class="svb-video-email__label" for="svb-email">Email для отримання посилання</label>
                <input class="svb-input" type="email" name="email" id="svb-email" placeholder="you@example.com" required />
              </div>

              <div class="svb-video-actions">
                <button class="svb-btn ghost" type="button" id="svb-back-3">Назад</button>

                <button class="svb-btn primary svb-video-download" type="button" id="svb-finish" disabled>
                  Завантажити відео
                </button>
              </div>

            </aside>

            <!-- RIGHT -->
             <?php
$video_poster = !empty($video_poster)
  ? $video_poster
  : 'https://e-santaa.com/wp-content/uploads/2025/11/posEr.png';
?>

              <div class="svb-video-right">
                <div class="svb-video-preview">
                 <div class="svb-video-preview__bg">
  <img
    id="svb-video-poster"
    class="svb-video-bg"
    src="<?php echo esc_url($video_poster); ?>"
    alt="<?php echo esc_attr__( 'Попередній перегляд відео', 'santa' ); ?>"
  >
</div>


                  <div class="svb-video-preview__media" id="svb-result"></div>

                  <div class="svb-video-preview__overlay" id="svb-video-overlay" aria-live="polite">
                    <div class="svb-video-progress">
                      <div class="svb-video-loader-gif" aria-hidden="true"></div>

                      <div class="svb-video-progress__text" id="svb-video-percent">Створення відео – 0%</div>

                      <strong class="svb-video-progress__status" id="svb-status">
                        Починаємо збірку відео…
                      </strong>
                    </div>
                  </div>

                </div>
              </div>

          </div>
        </div>

      </section>
    </form>
  </div>
</div>

  <div class="svb-modal" id="svb-video-modal" onclick="svbCloseModal(event)">
  <div class="svb-modal-content">
    <div class="svb-modal-close" onclick="svbCloseModal(event, true)">&times;</div>
    <video id="svb-modal-video" controls controlsList="nodownload" oncontextmenu="return false;"></video>
  </div>
</div>

<div class="svb-modal" id="svb-name-popup" onclick="svbCloseNamePopup(event)">
  <div class="svb-modal-content svb-req-popup-content">
    <div class="svb-modal-close svb-close-black" onclick="svbCloseNamePopup(event, true)">&times;</div>
    
    <h3>Запит на ім'я</h3>
    <div class="svb-note" style="text-align:center; margin-bottom:10px;">
        Шукайте тільки українською мовою. Уважно перегляньте весь перелік імен та спробуйте знайти інші форми потрібного імені. Якщо ж його все одно не виявиться, надішліть нам запит, і ми додамо це ім’я протягом найближчих 2–3 діб (зазвичай швидше).
    </div>

    <div class="svb-field">
        <label class="svb-label">Ім'я, стать, наголос</label>
        <input type="text" id="popup_req_name" class="svb-input" placeholder="Наприклад: Оленка (дівчинка), наголос на Е">
    </div>

    <div class="svb-field">
        <label class="svb-label">Ваш Email (для сповіщення)</label>
        <input type="email" id="popup_req_email" class="svb-input" placeholder="mail@example.com">
    </div>

    <button type="button" id="popup_req_submit" class="svb-btn primary" style="justify-content: center;">Надіслати запит</button>
  </div>
</div>
<div class="svb-modal" id="svb-crop-modal" style="display:none;">
    <div class="svb-modal-content svb-crop-content">
        <div class="svb-crop-header">
            <h3>Add a photo of the child:</h3> <div class="svb-crop-close" onclick="svbCloseCrop()">&times;</div>
        </div>
        
        <div class="svb-crop-body">
            <div class="svb-crop-img-container">
                <img id="svb-crop-target" src="" alt="Crop Preview">
            </div>
        </div>

        <div class="svb-crop-actions">
            <button type="button" class="svb-btn primary" id="svb-crop-save">Save</button>
        </div>
    </div>
</div>

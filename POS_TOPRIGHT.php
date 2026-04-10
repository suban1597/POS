<?php
// ============================================================
//  POS_TOPRIGHT.php  —  Shared Top-Right Info + Settings Panel
//  ต้อง include POS_SETTINGS.php ก่อนใช้งาน
//  ตัวแปรที่ใช้: $pos_logged_user, $pos_refresh_interval,
//                $pos_note, $pos_theme_color
//  Optional: set $pos_topright_show_online = true; ก่อน include
//            เพื่อแสดง Online/Offline counter
// ============================================================
$_ptr_show_online = isset($pos_topright_show_online) ? (bool)$pos_topright_show_online : false;
?>
<!-- ── Settings CSS (injected once per page) ─────────────── -->
<style>
:root { --theme-color: <?= htmlspecialchars($pos_theme_color) ?>; }
/* override hardcoded #0ff references in top-right to use variable */
.top-right { border-color: var(--theme-color) !important; box-shadow: 0 0 30px color-mix(in srgb, var(--theme-color) 40%, transparent) !important; }
.top-right.tr-collapsed { box-shadow: 0 0 16px color-mix(in srgb, var(--theme-color) 30%, transparent) !important; }

/* ── Settings toggle button ── */
.tr-settings-btn {
    background: none; border: none;
    color: var(--theme-color); cursor: pointer;
    font-size: 13px; padding: 2px 6px;
    margin-left: 6px; border-radius: 4px;
    transition: background 0.2s;
    box-shadow: none;
}
.tr-settings-btn:hover { background: rgba(0,255,255,0.12); transform: none; box-shadow: none; }

/* ── Settings form inside panel ── */
.tr-settings-panel {
    display: none;
    margin-top: 10px; padding-top: 10px;
    border-top: 1px solid var(--theme-color);
    font-size: 12px;
}
.tr-settings-panel.open { display: block; }
.tr-settings-panel > label {
    color: var(--theme-color); font-size: 11px;
    display: block; margin: 8px 0 3px;
}
.tr-settings-panel input[type=number],
.tr-settings-panel input[type=text] {
    padding: 5px 8px; border-radius: 4px;
    border: 1px solid var(--theme-color);
    background: #0a0a0a; color: #fff;
    font-size: 12px; font-family: Consolas, monospace;
}
.tr-settings-panel input[type=number] { width: 75px; }
.tr-settings-panel input[type=text]   { width: 100%; }
.tr-settings-panel input[type=color] {
    width: 40px; height: 28px; padding: 2px;
    border: 1px solid var(--theme-color);
    border-radius: 4px; background: #0a0a0a;
    cursor: pointer; vertical-align: middle;
}
.tr-settings-save-btn {
    margin-top: 10px; width: 100%; padding: 7px;
    background: linear-gradient(135deg, var(--theme-color), #0097a7);
    color: #fff; border: none; border-radius: 5px;
    cursor: pointer; font-size: 12px; font-weight: bold;
    box-shadow: none; transition: opacity 0.2s;
}
.tr-settings-save-btn:hover { opacity: 0.85; transform: none; box-shadow: none; }

/* ── Note display ── */
.tr-note-display {
    margin-top: 6px; padding: 6px 8px;
    background: rgba(255,204,0,0.08);
    border-left: 3px solid #ffcc00;
    border-radius: 4px; color: #ffcc00;
    font-size: 12px; white-space: pre-wrap; word-break: break-word;
}

/* ── Badge ร่วม (account expire / session / pwd) ── */
.tr-badge {
    font-size: 11px; font-weight: bold;
    padding: 1px 7px; border-radius: 10px;
    white-space: nowrap; display: inline-block;
}
/* account expire */
.tr-badge.exp-ok     { color:#ffb300; background:rgba(255,179,0,0.14); border:1px solid rgba(255,179,0,0.4); }
.tr-badge.exp-warn   { color:#ff8c00; background:rgba(255,100,0,0.18); border:1px solid rgba(255,100,0,0.5); }
.tr-badge.exp-danger { color:#ff4444; background:rgba(255,50,50,0.18); border:1px solid rgba(255,60,60,0.55); animation:badge-blink 1.1s ease-in-out infinite; }
/* session timeout */
.tr-badge.ses-warn   { color:#00e5ff; background:rgba(0,229,255,0.10); border:1px solid rgba(0,229,255,0.35); }
.tr-badge.ses-danger { color:#ff4444; background:rgba(255,50,50,0.18); border:1px solid rgba(255,60,60,0.55); animation:badge-blink 0.9s ease-in-out infinite; }
/* password age */
.tr-badge.pwd-warn   { color:#ce93d8; background:rgba(123,31,162,0.18); border:1px solid rgba(171,71,188,0.45); }
.tr-badge.pwd-danger { color:#ff4444; background:rgba(255,50,50,0.18); border:1px solid rgba(255,60,60,0.55); animation:badge-blink 1.1s ease-in-out infinite; }
/* shared blink */
@keyframes badge-blink { 0%,100%{opacity:1} 50%{opacity:0.35} }
</style>

<!-- ── Top-Right Panel HTML ─────────────────────────────── -->
<div class="top-right tr-collapsed" id="top-right-panel" title="คลิกเพื่อแสดงข้อมูล">

    <!-- ไอคอนย่อ (collapsed) -->
    <div class="tr-icon-mini" style="display:flex;align-items:center;gap:5px;white-space:nowrap;flex-wrap:wrap;">
        <i class="fas fa-info-circle" style="font-size:16px;color:var(--theme-color,#0ff);margin:0;"></i>
        <span style="font-size:12px;color:var(--theme-color,#0ff);">ข้อมูล</span>

        <?php /* ── Badge 1: วันหมดอายุบัญชี ── */
        $pos_expiry_days_left = $pos_expiry_days_left ?? null;
        $pos_expiry_status    = $pos_expiry_status    ?? 'ok';
        if ($pos_expiry_days_left !== null && $pos_expiry_days_left <= 30):
            if ($pos_expiry_status === 'expired'):
                $exp_cls = 'exp-danger'; $exp_txt = '⚠ หมดอายุแล้ว';
            elseif ($pos_expiry_days_left === 0):
                $exp_cls = 'exp-danger'; $exp_txt = '⚠ หมดอายุวันนี้';
            elseif ($pos_expiry_days_left <= 7):
                $exp_cls = 'exp-warn';   $exp_txt = '⏳ ' . $pos_expiry_days_left . 'วัน';
            else:
                $exp_cls = 'exp-ok';     $exp_txt = '📅 ' . $pos_expiry_days_left . 'วัน';
            endif; ?>
        <span class="tr-badge <?= $exp_cls ?>"><?= htmlspecialchars($exp_txt) ?></span>
        <?php endif; ?>

        <?php /* ── Badge 2: Session timeout (JS จะอัปเดต) ── */ ?>
        <span id="tr-ses-badge" class="tr-badge ses-warn" style="display:none;">⏱ –:--</span>

        <?php /* ── Badge 3: อายุรหัสผ่าน ── */
        $pos_pwd_days_left = $pos_pwd_days_left ?? null;
        $pos_pwd_status    = $pos_pwd_status    ?? 'ok';
        if ($pos_pwd_days_left !== null && $pos_pwd_status !== 'ok'):
            $pwd_cls = $pos_pwd_status === 'expired' ? 'pwd-danger' : 'pwd-warn';
            $pwd_txt = $pos_pwd_status === 'expired'
                ? '🔑 รหัสผ่านหมดอายุ'
                : '🔑 รหัสผ่าน ' . $pos_pwd_days_left . 'วัน'; ?>
        <span class="tr-badge <?= $pwd_cls ?>"><?= htmlspecialchars($pwd_txt) ?></span>
        <?php endif; ?>
    </div>

    <!-- เนื้อหาเต็ม (expanded) -->
    <div class="tr-content">
        <!-- user + logout + gear -->
        <div style="margin-bottom:8px;color:#0f0;font-size:12px;display:flex;align-items:center;flex-wrap:wrap;gap:4px;">
            <a href="POS_USER.php" style="color:#0f0;text-decoration:none;">
                <i class="fas fa-user-circle"></i>
                <?= htmlspecialchars($pos_logged_user ?? '') ?>
                <i class="fas fa-key" style="color:#ffb300;font-size:11px;margin-left:2px;"></i>
            </a>
            &nbsp;|&nbsp;
            <a href="logout.php" style="color:#ff6b6b;text-decoration:none;font-weight:bold;"
               onclick="return confirm('ออกจากระบบ?')">
                <i class="fas fa-sign-out-alt"></i> ออกจากระบบ
            </a>
            <button class="tr-settings-btn" title="ตั้งค่า"
                    onclick="event.stopPropagation(); posToggleSettings();">
                <i class="fas fa-cog"></i>
            </button>
        </div>

        <!-- clock + date range -->
        <i class="fas fa-clock"></i> <span id="refresh-time">-</span><br>
        <div style="margin-top:5px;color:#ffcc00;">
            <i class="fas fa-calendar"></i> <span id="date-range">-</span>
            <?php if ($_ptr_show_online): ?><br>
            Online: <span id="online-machines">0</span> / Offline: <span id="offline-machines">0</span>
            <?php endif; ?>
        </div>

        <!-- pwd expiry status -->
        <?php
        $pos_pwd_days_left = $pos_pwd_days_left ?? null;
        $pos_pwd_status    = $pos_pwd_status    ?? 'ok';
        if ($pos_pwd_days_left !== null):
            if ($pos_pwd_status === 'expired'):
                $_pwd_color = '#ff4444'; $_pwd_icon = 'fa-lock'; $_pwd_msg = 'รหัสผ่าน<strong>หมดอายุแล้ว</strong> — กรุณาเปลี่ยนที่ <a href="POS_USER.php" style="color:#ff4444;text-decoration:underline;">หน้าตั้งค่า</a>';
            elseif ($pos_pwd_status === 'warning'):
                $_pwd_color = '#ce93d8'; $_pwd_icon = 'fa-key'; $_pwd_msg = 'รหัสผ่านเหลือ <strong>' . (int)$pos_pwd_days_left . ' วัน</strong>';
            else:
                $_pwd_color = '#4caf50'; $_pwd_icon = 'fa-shield-alt'; $_pwd_msg = 'รหัสผ่านเหลือ <strong>' . (int)$pos_pwd_days_left . ' วัน</strong>';
            endif;
        ?>
        <div style="margin-top:6px;padding:5px 8px;background:rgba(171,71,188,0.08);border-left:3px solid <?= $_pwd_color ?>;border-radius:4px;font-size:11px;">
            <i class="fas <?= $_pwd_icon ?>" style="color:<?= $_pwd_color ?>;margin-right:5px;"></i>
            <span style="color:<?= $_pwd_color ?>"><?= $_pwd_msg ?></span>
        </div>
        <?php endif; ?>

        <!-- note display -->
        <div class="tr-note-display" id="tr-note-display"
             style="<?= $pos_note === '' ? 'display:none;' : '' ?>">
            <?= htmlspecialchars($pos_note) ?>
        </div>

        <!-- Settings form (hidden by default) -->
        <div class="tr-settings-panel" id="tr-settings-panel">

            <label><i class="fas fa-sync-alt"></i> Auto-refresh (วินาที)</label>
            <input type="number" id="cfg-refresh" min="5" max="300"
                   value="<?= (int)$pos_refresh_interval ?>"
                   onclick="event.stopPropagation();">
            <span style="color:#888;font-size:11px;margin-left:4px;">(5–300)</span>

            <label><i class="fas fa-compress-alt"></i> ย่อแผงอัตโนมัติ (วินาที)</label>
            <input type="number" id="cfg-collapse" min="3" max="60"
                   value="<?= (int)($pos_collapse_delay ?? 10) ?>"
                   onclick="event.stopPropagation();">
            <span style="color:#888;font-size:11px;margin-left:4px;">(3–60)</span>

            <label><i class="fas fa-palette"></i> ธีมสี</label>
            <input type="color" id="cfg-theme-color"
                   value="<?= htmlspecialchars($pos_theme_color) ?>"
                   onclick="event.stopPropagation();"
                   oninput="posPreviewColor(this.value)">
            <span id="cfg-theme-hex"
                  style="color:#aaa;font-size:11px;margin-left:6px;vertical-align:middle;">
                <?= htmlspecialchars($pos_theme_color) ?>
            </span>

            <label><i class="fas fa-sticky-note"></i> โน้ตส่วนตัว</label>
            <input type="text" id="cfg-note" maxlength="200"
                   value="<?= htmlspecialchars($pos_note) ?>"
                   placeholder="ข้อความที่ต้องการแสดง..."
                   onclick="event.stopPropagation();">

            <button class="tr-settings-save-btn"
                    onclick="event.stopPropagation(); posSaveSettings();">
                <i class="fas fa-save"></i> บันทึกการตั้งค่า
            </button>
            <div id="cfg-msg" style="margin-top:6px;font-size:11px;text-align:center;display:none;"></div>
        </div>
    </div>
</div>

<!-- ── Settings JS ───────────────────────────────────────── -->
<script>
// ── preview สีก่อนบันทึก ────────────────────────────────
function posPreviewColor(hex) {
    document.documentElement.style.setProperty('--theme-color', hex);
    const el = document.getElementById('cfg-theme-hex');
    if (el) el.textContent = hex;
}

// ── toggle settings panel ────────────────────────────────
function posToggleSettings() {
    const p = document.getElementById('tr-settings-panel');
    if (p) p.classList.toggle('open');
}

// ── บันทึกค่าและ reload ─────────────────────────────────
function posSaveSettings() {
    const ri    = parseInt(document.getElementById('cfg-refresh').value)  || 15;
    const cd    = parseInt(document.getElementById('cfg-collapse').value) || 10;
    const color = document.getElementById('cfg-theme-color').value;
    const note  = document.getElementById('cfg-note').value;
    const msg   = document.getElementById('cfg-msg');

    const fd = new FormData();
    fd.append('refresh_interval', Math.max(5,  Math.min(300, ri)));
    fd.append('collapse_delay',   Math.max(3,  Math.min(60,  cd)));
    fd.append('theme_color', color);
    fd.append('note', note);

    const saveURL = window.location.pathname + '?save_settings=1';
    fetch(saveURL, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (!d.ok) throw new Error('not ok');
            document.documentElement.style.setProperty('--theme-color', d.theme_color);

            // อัปเดต note ทันที
            const nd = document.getElementById('tr-note-display');
            if (nd) {
                if (d.note && d.note.trim() !== '') {
                    nd.textContent = d.note;
                    nd.style.display = '';
                } else {
                    nd.style.display = 'none';
                    nd.textContent = '';
                }
            }

            // อัปเดต collapse delay ทันที (ไม่ต้อง reload เพื่อให้มีผล)
            if (window._posSetCollapseDelay) window._posSetCollapseDelay(d.collapse_delay * 1000);

            msg.style.color   = '#0f0';
            msg.textContent   = '✓ บันทึกแล้ว — กำลัง reload…';
            msg.style.display = 'block';
            setTimeout(() => location.reload(), 900);
        })
        .catch(() => {
            if (msg) {
                msg.style.color   = '#f66';
                msg.textContent   = '✗ บันทึกไม่สำเร็จ';
                msg.style.display = 'block';
            }
        });
}

// ── expand/collapse behaviour ────────────────────────────
(function() {
    const panel = document.getElementById('top-right-panel');
    if (!panel) return;
    let collapseTimer = null;
    let collapseDelay = <?= (int)($pos_collapse_delay ?? 10) ?> * 1000;

    function expand() {
        panel.classList.remove('tr-collapsed');
        if (collapseTimer) clearTimeout(collapseTimer);
        collapseTimer = setTimeout(collapse, collapseDelay);
    }
    function collapse() {
        panel.classList.add('tr-collapsed');
        const sp = document.getElementById('tr-settings-panel');
        if (sp) sp.classList.remove('open');
        if (collapseTimer) { clearTimeout(collapseTimer); collapseTimer = null; }
    }
    // expose ให้ posSaveSettings อัปเดตค่าได้ทันทีโดยไม่ต้อง reload
    window._posSetCollapseDelay = function(ms) {
        collapseDelay = Math.max(3000, Math.min(60000, ms));
    };
    panel.addEventListener('click', function(e) {
        if (!panel.classList.contains('tr-collapsed')) return;
        e.preventDefault();
        expand();
    });
    expand(); // โหลดหน้า: แสดงเต็ม N วิ แล้วย่อ
})();

// ── Session Timeout Countdown ────────────────────────────────
(function () {
    const WARN_SECS   = 300;  // เริ่มแสดงเมื่อเหลือ ≤ 5 นาที
    const DANGER_SECS = 60;   // เปลี่ยนเป็นแดงเมื่อเหลือ ≤ 1 นาที
    let remaining = <?= (int)($pos_session_remaining ?? 1440) ?>;
    const el = document.getElementById('tr-ses-badge');
    if (!el) return;

    function fmtTime(s) {
        const m = Math.floor(s / 60), ss = s % 60;
        return m + ':' + String(ss).padStart(2, '0');
    }
    function tickSession() {
        if (remaining <= 0) {
            el.textContent = '⏱ หมดเซสชัน';
            el.className   = 'tr-badge ses-danger';
            el.style.display = '';
            return;
        }
        if (remaining <= WARN_SECS) {
            el.style.display = '';
            el.textContent   = '⏱ ' + fmtTime(remaining);
            el.className     = 'tr-badge ' + (remaining <= DANGER_SECS ? 'ses-danger' : 'ses-warn');
        } else {
            el.style.display = 'none';
        }
        remaining--;
        setTimeout(tickSession, 1000);
    }
    tickSession();
})();
</script>

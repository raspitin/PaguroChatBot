<?php
/**
 * SHORTCODE: Chat Widget
 * [paguro_chat]
 */

if (!defined('ABSPATH')) exit;

function paguro_shortcode_chat() {
    $icon_url = plugin_dir_url(dirname(__FILE__)) . 'paguro_bot_icon.png';
    
    ob_start(); ?>
    <div class="paguro-chat-root paguro-mode-inline">
        <div class="paguro-chat-launcher">
            <img src="<?php echo esc_url($icon_url); ?>" alt="Paguro Bot">
        </div>
        
        <div class="paguro-chat-window">
            <div class="paguro-chat-header">
                <span>Paguro Booking</span>
                <span class="close-btn">&times;</span>
            </div>
            
            <div class="paguro-chat-body">
                <div class="paguro-msg paguro-msg-bot">
                    <img src="<?php echo esc_url($icon_url); ?>" class="paguro-bot-avatar" alt="Paguro">
                    <div class="paguro-msg-content">
                        <strong>Ciao! Sono Paguro 🐚</strong><br>
                        Chiedimi disponibilità per i mesi estivi.<br>
                        <br>
                        <div class="paguro-month-buttons">
                            <button class="paguro-quick-btn" data-msg="Disponibilità Giugno">📅 Giugno</button>
                            <button class="paguro-quick-btn" data-msg="Disponibilità Luglio">📅 Luglio</button>
                            <button class="paguro-quick-btn" data-msg="Disponibilità Agosto">📅 Agosto</button>
                            <button class="paguro-quick-btn" data-msg="Disponibilità Settembre">📅 Settembre</button>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="paguro-chat-footer">
                <input type="text" class="paguro-input-field" placeholder="Es: 'Luglio due settimane'..." autocomplete="off">
                <button type="button" class="paguro-send-btn" title="Invia">➤</button>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

add_shortcode('paguro_chat', 'paguro_shortcode_chat');

// Flag per evitare rendering multiplo del widget globale
if (!defined('PAGURO_CHAT_DEFINED')) define('PAGURO_CHAT_DEFINED', true);

?>

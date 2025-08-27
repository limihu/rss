<?php
/*
Plugin Name: 朋友圈RSS
Description: 基于WordPress链接和RSS聚合展示朋友圈风格文章
Version: 1.0
Author: limihu
Author URI: https://blog.toocool.cc/
Plugin URI: https://blog.toocool.cc/
*/

if (!defined('ABSPATH')) exit;

function friendcircle_default_options() {
    return array(
        'rss_items_per_link' => 4,
        'cards_per_page'     => 10,
        'cards_per_row'      => 2,
        'cache_minutes'      => 60,
    );
}

function friendcircle_get_option($key) {
    $defaults = friendcircle_default_options();
    $options = get_option('friendcircle_settings', $defaults);
    return isset($options[$key]) ? $options[$key] : $defaults[$key];
}

add_shortcode('friendcircle_wall', 'friendcircle_display_feeds');

function friendcircle_display_feeds($atts) {
    include_once(ABSPATH . WPINC . '/feed.php');

    $rss_items_per_link = intval(friendcircle_get_option('rss_items_per_link'));
    $cards_per_page     = intval(friendcircle_get_option('cards_per_page'));
    $cards_per_row      = intval(friendcircle_get_option('cards_per_row'));
    $cache_minutes      = intval(friendcircle_get_option('cache_minutes'));
    if ($cache_minutes < 1) $cache_minutes = 30;

    $cache_key = 'friendcircle_cache';
    $cached = get_transient($cache_key);

    if ($cached !== false && is_array($cached) && isset($cached['all_items']) && isset($cached['categories'])) {
        $all_items  = $cached['all_items'];
        $categories = $cached['categories'];
    } else {
        $link_categories = get_terms(array('taxonomy'=>'link_category','hide_empty'=>false));
        $all_items  = array();
        $categories = array();

        if ($link_categories && !is_wp_error($link_categories)) {
            foreach ($link_categories as $cat) {
                $bookmarks = get_bookmarks(array('category'=>$cat->term_id));
                $cat_has_rss = false;

                foreach ($bookmarks as $bm) {
                    if (empty($bm->link_rss)) continue;
                    $rss = fetch_feed($bm->link_rss);
                    if (is_wp_error($rss)) continue;

                    $cat_has_rss = true;
                    $maxitems = $rss->get_item_quantity($rss_items_per_link);
                    $rss_items = $rss->get_items(0, $maxitems);

                    foreach ($rss_items as $item) {
                        $plink = method_exists($item,'get_permalink') ? $item->get_permalink() : (method_exists($item,'get_link') ? $item->get_link() : '');
                        if (!$plink) continue;

                        $all_items[] = array(
                            'title'       => $item->get_title(),
                            'link'        => $plink,
                            'date'        => $item->get_date('U'),
                            'description' => $item->get_description(),
                            'source_name' => $bm->link_name,
                            'avatar'      => $bm->link_image,
                            'categories'  => array($cat->term_id)
                        );
                    }
                }
                if ($cat_has_rss) $categories[$cat->term_id] = $cat->name;
            }
        }

        usort($all_items, function($a,$b){ return intval($b['date']) - intval($a['date']); });

        set_transient($cache_key, array(
            'all_items'  => $all_items,
            'categories' => $categories,
        ), $cache_minutes * MINUTE_IN_SECONDS);
    }

    ob_start();
    ?>
    <div class="friendcircle-container">
        <div class="friendcircle-filter">
            <button class="friendcircle-filter-btn active" data-cat="">全部</button>
            <?php foreach($categories as $cid=>$cname): ?>
                <button class="friendcircle-filter-btn" data-cat="<?php echo esc_attr($cid); ?>"><?php echo esc_html($cname); ?></button>
            <?php endforeach; ?>
        </div>

        <div class="friendcircle-wall" style="--cards-per-row: <?php echo $cards_per_row; ?>;">
            <?php foreach($all_items as $idx=>$item): ?>
                <?php $cats_attr = implode(' ', array_map(function($c){ return 'cat-'.$c; }, $item['categories'])); ?>
                <div class="friendcircle-card <?php echo esc_attr($cats_attr); ?>" data-href="<?php echo esc_url($item['link']); ?>" data-index="<?php echo $idx; ?>" style="display:none;">
                    <div class="friendcircle-avatar">
                        <?php if(!empty($item['avatar'])): ?>
                            <img src="<?php echo esc_url($item['avatar']); ?>" alt="avatar" loading="lazy">
                        <?php else: ?>
                            <div class="friendcircle-avatar-placeholder">
                                <span><?php echo esc_html(substr($item['source_name'], 0, 2)); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="friendcircle-content">
                        <div class="friendcircle-header">
                            <span class="friendcircle-source"><?php echo esc_html($item['source_name']); ?></span>
                            <?php if(!empty($item['date'])): ?>
                                <span class="friendcircle-date"><?php echo esc_html(date('Y-m-d H:i',intval($item['date']))); ?></span>
                            <?php endif; ?>
                        </div>
                        <h3 class="friendcircle-title">
                            <a class="friendcircle-title-link" href="<?php echo esc_url($item['link']); ?>" target="_blank" rel="noopener noreferrer">
                                <?php echo esc_html($item['title']); ?>
                            </a>
                        </h3>
                        <p class="friendcircle-desc"><?php echo esc_html(wp_trim_words(wp_strip_all_tags($item['description']),35)); ?></p>
                        <?php if(!empty($item['categories'])): ?>
                            <?php $catnames = array(); foreach($item['categories'] as $cid){ if(isset($categories[$cid])) $catnames[]=$categories[$cid]; } ?>
                            <?php if($catnames): ?>
                                <div class="friendcircle-tags">
                                    <?php foreach($catnames as $tag): ?>
                                        <span class="friendcircle-tag"><?php echo esc_html($tag); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="friendcircle-loadmore" style="text-align:center;margin-top:20px;">
            <button id="friendcircle-loadmore">加载更多</button>
        </div>
    </div>

    <style>
.friendcircle-container{max-width:1200px;margin:0 auto;padding:20px}
.friendcircle-filter{margin-bottom:30px;text-align:center;overflow-x:auto;padding:10px 0;scrollbar-width:thin}
.friendcircle-filter::-webkit-scrollbar{height:6px}
.friendcircle-filter::-webkit-scrollbar-thumb{background-color:#ddd;border-radius:3px}
.friendcircle-filter-btn{padding:8px 18px;margin:0 8px 10px;border:none;border-radius:20px;background-color:var(--theme-color-op10);color:var(--theme-color-pri);cursor:pointer;font-weight:500;transition:all 0.3s ease;white-space:nowrap;box-shadow:0 2px 4px rgba(0,0,0,0.05)}
.friendcircle-filter-btn:hover{background:#e9ecef;transform:translateY(-2px)}
.friendcircle-filter-btn.active{background:var(--theme-color-pri);color:#fff;box-shadow:0 4px 8px rgb(122 122 122 / 20%)}
.friendcircle-wall{display:grid;grid-template-columns:repeat(var(--cards-per-row),1fr);gap:24px;margin-bottom:30px}
@media(max-width:1024px){.friendcircle-wall{grid-template-columns:repeat(auto-fit,minmax(300px,1fr))}}
@media(max-width:768px){.friendcircle-wall{grid-template-columns:1fr;gap:16px}}
.friendcircle-card{display:flex;background-color:var(--bgc-box);border:1px solid var(--border-box);padding:20px;border-radius:16px;align-items:flex-start;cursor:pointer;transition:all 0.3s ease;position:relative;overflow:hidden}
.friendcircle-card::before{content:'';position:absolute;top:0;left:0;width:4px;height:100%;background:var(--theme-color-pri);opacity:0;transition:opacity 0.3s ease}
.friendcircle-card:hover{transform:translateY(-5px)}
.friendcircle-card:hover::before{opacity:1}
.friendcircle-avatar{flex-shrink:0}
.friendcircle-avatar img{width:56px;height:56px;border-radius:50%;margin-right:16px;object-fit:cover;transition:transform 0.3s ease}
.friendcircle-card:hover .friendcircle-avatar img{transform:scale(1.05)}
.friendcircle-avatar-placeholder{width:56px;height:56px;border-radius:50%;margin-right:16px;background:#e9ecef;display:flex;align-items:center;justify-content:center;color:#495057;font-weight:600;font-size:18px;border:2px solid #f1f3f5}
.friendcircle-content{flex:1;min-width:0}
.friendcircle-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;font-size:13px}
.friendcircle-source{color:#c7c7c7;font-size:13px !important}
.friendcircle-date{color:#c7c7c7;font-size:13px !important}
.friendcircle-title{margin-top:-5px !important;margin-bottom:12px;font-size:17px;font-weight:600;line-height:1.5;transition:color 0.3s ease}
.friendcircle-title-link{color:#212529;text-decoration:none;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.friendcircle-title-link:hover{color:#3b7adb;text-decoration:none}
.friendcircle-desc{margin:0 0 15px 0;color:#929292;font-size:14px !important;line-height:1.6;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
.friendcircle-tags{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}
.friendcircle-tag{font-size:12px !important;padding:3px 10px;background:var(--bgc-footer);color:#a5abb1;border-radius:12px;transition:all 0.2s ease}
.friendcircle-card:hover .friendcircle-tag{box-shadow:0.2px 0.1px 0px var(--theme-color-pri)}
#friendcircle-loadmore{padding:10px 24px;background:var(--theme-color-pri);color:#fff;border:none;border-radius:8px;cursor:pointer;font-weight:500;transition:all 0.3s ease;font-size:15px}
#friendcircle-loadmore:hover{transform:translateY(-2px);box-shadow:0 6px 16px var(--theme-color-pri)}
#friendcircle-loadmore:active{transform:translateY(0)}
    </style>

    <script>
(function(){
    const perPage=<?php echo intval($cards_per_page); ?>;
    const allCards=Array.from(document.querySelectorAll('.friendcircle-card'));
    let filteredCards=[...allCards];
    let current=0;
    const loadMoreBtn=document.getElementById('friendcircle-loadmore');
    const wall=document.querySelector('.friendcircle-wall');
    wall.style.setProperty('--cards-per-row',<?php echo intval($cards_per_row); ?>);

    function showNext(){
        const next=filteredCards.slice(current,current+perPage);
        next.forEach((card,index)=>{
            setTimeout(()=>{
                card.style.display='flex';
                card.style.opacity='0';
                card.style.transform='translateY(10px)';
                void card.offsetWidth;
                card.style.transition='opacity 0.4s ease, transform 0.4s ease';
                card.style.opacity='1';
                card.style.transform='translateY(0)';
            },index*50);
        });
        current+=perPage;
        loadMoreBtn.style.display=current>=filteredCards.length?'none':'inline-block';
    }

    showNext();
    loadMoreBtn.addEventListener('click',showNext);

    document.querySelectorAll('.friendcircle-filter-btn').forEach(btn=>{
        btn.addEventListener('click',function(){
            document.querySelectorAll('.friendcircle-filter-btn').forEach(b=>b.classList.remove('active'));
            this.classList.add('active');
            const cat=this.getAttribute('data-cat');
            filteredCards=cat?allCards.filter(c=>c.classList.contains('cat-'+cat)):[...allCards];
            allCards.forEach(card=>{
                card.style.opacity='0';
                card.style.transform='translateY(10px)';
                setTimeout(()=>{card.style.display='none'},300);
            });
            setTimeout(()=>{current=0;showNext()},300);
        });
    });

    allCards.forEach(card=>{
        card.addEventListener('click',function(e){
            if(e.target.closest('a')) return;
            const url=this.getAttribute('data-href');
            if(url) window.open(url,'_blank');
        });
    });
})();
function copyFriendcircleCode(){
    const codeEl=document.getElementById('friendcircleCode');
    const range=document.createRange();
    range.selectNode(codeEl);
    window.getSelection().removeAllRanges();
    window.getSelection().addRange(range);
    try{document.execCommand('copy');alert('短代码已复制到剪贴板！')}catch(err){alert('复制失败，请手动复制')}
    window.getSelection().removeAllRanges();
}
</script>
    <?php
    return ob_get_clean();
}

add_action('admin_menu',function(){
    add_menu_page('朋友圈RSS','朋友圈RSS','manage_options','friendcircle_settings','friendcircle_settings_page','dashicons-networking',80);
});

function friendcircle_settings_page(){
    $options=get_option('friendcircle_settings',friendcircle_default_options());

    if(isset($_POST['friendcircle_clear_cache'])&&check_admin_referer('friendcircle_clear_cache','friendcircle_clear_nonce')){
        delete_transient('friendcircle_cache');
        echo '<div class="updated"><p>缓存已清空。</p></div>';
        $options=get_option('friendcircle_settings',friendcircle_default_options());
    }

    if(isset($_POST['friendcircle_save_settings'])&&check_admin_referer('friendcircle_save_settings','friendcircle_nonce')){
        $options['rss_items_per_link']=intval($_POST['rss_items_per_link']);
        $options['cards_per_page']=intval($_POST['cards_per_page']);
        $options['cards_per_row']=intval($_POST['cards_per_row']);
        $options['cache_minutes']=intval($_POST['cache_minutes']);
        if($options['cache_minutes']<1)$options['cache_minutes']=1;
        update_option('friendcircle_settings',$options);
        delete_transient('friendcircle_cache');
        echo '<div class="updated"><p>设置已保存并清理缓存。</p></div>';
    }
    ?>
    <div class="wrap">
        <h1>朋友圈RSS</h1>
        <form method="post">
            <?php wp_nonce_field('friendcircle_save_settings','friendcircle_nonce'); ?>
            <input type="hidden" name="friendcircle_save_settings" value="1">
            <table class="form-table">
                <tr>
                    <th>每个 RSS 链接抓取文章数量</th>
                    <td><input type="number" name="rss_items_per_link" value="<?php echo esc_attr($options['rss_items_per_link']); ?>" min="1" max="20"></td>
                </tr>
                <tr>
                    <th>前台每页加载文章数量</th>
                    <td><input type="number" name="cards_per_page" value="<?php echo esc_attr($options['cards_per_page']); ?>" min="1" max="50"></td>
                </tr>
                <tr>
                    <th>前台每行显示卡片数量</th>
                    <td><input type="number" name="cards_per_row" value="<?php echo esc_attr($options['cards_per_row']); ?>" min="1" max="4"></td>
                </tr>
                <tr>
                    <th>缓存时间（分钟）</th>
                    <td><input type="number" name="cache_minutes" value="<?php echo esc_attr($options['cache_minutes']); ?>" min="1" max="1440"></td>
                </tr>
            </table>
            <?php submit_button('保存设置'); ?>
        </form>

        <hr>

        <form method="post" style="margin-top:15px;">
            <?php wp_nonce_field('friendcircle_clear_cache','friendcircle_clear_nonce'); ?>
            <input type="hidden" name="friendcircle_clear_cache" value="1">
            <?php submit_button('清空缓存', 'secondary'); ?>
        </form>

        <div style="max-width:500px;margin-top:25px;margin-left:0;padding:20px;border-radius:10px;background:linear-gradient(90deg,#f5f7fa,#e4e9f0);border:1px solid #d1d5db;box-shadow:0 2px 8px rgba(0,0,0,0.05);">
            <h3 style="margin-top:0;font-size:18px;color:#111;font-weight:600;">使用方法</h3>
            <ol style="padding-left:20px;color:#333;font-size:14px;line-height:1.6;">
                <li>直接复制以下短代码粘贴到你需要展示的文章或页面即可</li>
                <li>请直接在左侧菜单栏点击链接添加 RSS 信息，注意需要在链接编辑页面增加 RSS 地址</li>
                <li>可设置链接分类，这些都会自动获取</li>
            </ol>
            <div style="margin-top:15px;display:flex;align-items:center;gap:10px;">
                <pre id="friendcircleCode" style="background:#fff;padding:10px 15px;border:1px solid #ccc;border-radius:6px;font-size:13px;margin:0;flex:1;overflow-x:auto;">[friendcircle_wall]</pre>
                <button type="button" onclick="copyFriendcircleCode()" style="padding:8px 15px;background:#4f46e5;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:13px;transition:0.2s;">复制</button>
            </div>
            <div style="display:flex;justify-content:space-between;margin-top:15px;font-size:13px;color:#333;align-items:center;">
                <a href="https://blog.toocool.cc/297.html" target="_blank" style="color:#4f46e5;text-decoration:underline;">查看详细使用说明</a>
                <div>
                    作者：<a href="https://blog.toocool.cc" target="_blank" style="color:#4f46e5;text-decoration:underline;margin-right:15px;">limihu</a>
                    <a href="https://blog.toocool.cc/chongdian" target="_blank" style="color:#f59e0b;font-weight:600;text-decoration:underline;">给作者充电⚡️</a>
                </div>
            </div>
        </div>

        <script>
        function copyFriendcircleCode(){
            const codeEl=document.getElementById('friendcircleCode');
            const range=document.createRange();
            range.selectNode(codeEl);
            window.getSelection().removeAllRanges();
            window.getSelection().addRange(range);
            try{document.execCommand('copy');alert('短代码已复制到剪贴板！')}catch(err){alert('复制失败，请手动复制')}
            window.getSelection().removeAllRanges();
        }
        </script>
    </div>
    <?php
}
?>

<?php
// includes/sidebar.php
// ランキング・アフィリエイトリンク用サイドバー
$show_affiliate_links = false;

if (!$show_affiliate_links) {
    return;
}
?>

<aside class="side-box" aria-label="ランキング・おすすめリンク">

    <section class="side-section">
        <div class="side-box-title">コミックランキング</div>

        <ol class="side-ranking">
            <li>
                <a href="https://yahoo.jp/DpCK2Y" target="_blank" rel="nofollow sponsored noopener">
                    Yahoo!ショッピング<br>ランキング
                </a>
            </li>
            <li>
                <a href="https://amzn.to/4dntq5U" target="_blank" rel="nofollow sponsored noopener">
                    Amazon ランキング
                </a>
            </li>
            <li>
                <a href="https://a.r10.to/h8Zv5U" target="_blank" rel="nofollow sponsored noopener">
                    楽天ブックス ランキング
                </a>
            </li>
        </ol>

        <div class="side-note">

        </div>
    </section>

    <section class="side-section">
        <div class="side-box-title">商品を探す</div>

        <div class="side-search-box">
            <label class="side-search-label" for="sideSearchKeyword">検索ワード</label>
            <input
                type="text"
                id="sideSearchKeyword"
                class="side-search-input"
                placeholder="作品名・作者名・雑誌名"
                autocomplete="off"
            >
        </div>

        <div class="side-search-row">
            <span class="side-search-name">楽天市場で探す</span>
            <button type="button" class="side-search-button" data-search-site="rakuten">探す</button>
        </div>

        <div class="side-search-row">
            <span class="side-search-name">Amazonで探す</span>
            <button type="button" class="side-search-button" data-search-site="amazon">探す</button>
        </div>

        <div class="side-search-row">
            <span class="side-search-name">Yahoo!ショッピングで探す</span>
            <button type="button" class="side-search-button" data-search-site="yahoo">探す</button>
        </div>

        <div class="side-search-row">
            <span class="side-search-name">駿河屋で探す</span>
            <button type="button" class="side-search-button" data-search-site="surugaya">探す</button>
        </div>

        <div class="side-search-row">
            <span class="side-search-name">メルカリで探す</span>
            <button type="button" class="side-search-button" data-search-site="mercari">探す</button>
        </div>

        <div class="side-search-row">
            <span class="side-search-name">ヤフオクで探す</span>
            <button type="button" class="side-search-button" data-search-site="yahooauc">探す</button>
        </div>

        <div class="side-search-row">
            <span class="side-search-name">オークファンで相場を探す</span>
            <button type="button" class="side-search-button" data-search-site="aucfan">探す</button>
        </div>
    </section>

</aside>

<style>
.side-search-box {
    margin: 8px 0 10px;
}

.side-search-label {
    display: block;
    margin-bottom: 5px;
    font-size: 12px;
    color: #666;
}

.side-search-input {
    width: 100%;
    box-sizing: border-box;
    padding: 8px 9px;
    border: 1px solid #ccc;
    border-radius: 8px;
    font-size: 13px;
    background: #fff;
}

.side-search-input:focus {
    outline: none;
    border-color: #999;
    box-shadow: 0 0 0 3px rgba(0, 0, 0, 0.06);
}

.side-search-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 8px 0;
    border-bottom: 1px solid #eee;
}

.side-search-name {
    min-width: 0;
    font-size: 13px;
    line-height: 1.4;
    color: #333;
}

.side-search-button {
    flex: 0 0 auto;
    padding: 5px 10px;
    border: 1px solid #222;
    border-radius: 999px;
    background: #222;
    color: #fff;
    font-size: 12px;
    line-height: 1.2;
    cursor: pointer;
}

.side-search-button:hover {
    opacity: 0.82;
}

.side-search-button:active {
    transform: translateY(1px);
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const keywordInput = document.getElementById('sideSearchKeyword');
    const buttons = document.querySelectorAll('.side-search-button[data-search-site]');

    const searchUrls = {
        rakuten: 'https://search.rakuten.co.jp/search/mall/',
        amazon: 'https://www.amazon.co.jp/s?k=',
        yahoo: 'https://shopping.yahoo.co.jp/search?p=',
        surugaya: 'https://www.suruga-ya.jp/search?category=&search_word=',
        mercari: 'https://jp.mercari.com/search?keyword=',
        yahooauc: 'https://auctions.yahoo.co.jp/search/search?p=',
        aucfan: 'aucfan_search.php?q='
    };

    function openSearch(site) {
        const keyword = keywordInput.value.trim();

        if (!keyword) {
            keywordInput.focus();
            return;
        }

        const encodedKeyword = encodeURIComponent(keyword);
        const baseUrl = searchUrls[site];

        if (!baseUrl) {
            return;
        }

        let url = '';

        if (site === 'rakuten') {
            url = baseUrl + encodedKeyword + '/';
        } else {
            url = baseUrl + encodedKeyword;
        }

        window.open(url, '_blank', 'noopener');
    }

    buttons.forEach(function (button) {
        button.addEventListener('click', function () {
            openSearch(button.dataset.searchSite);
        });
    });

    keywordInput.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            openSearch('rakuten');
        }
    });
});
</script>

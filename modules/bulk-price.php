<div class="panel modulePanel hide" id="bulkPriceModule">
    <div class="bulkHead">
      <div>
        <h2>Toplu Fiyat Hesaplama</h2>
        <p>T-Soft'tan ürün ara, seç; ürün otomatik listelenir ve satış fiyatı anında hesaplanır.</p>
      </div>
    </div>

    <div class="body">
      <div class="bulkDefaults">
        <div>
          <label>Kâr %</label>
          <input id="bulkDefaultProfit" type="number" min="5" max="100" value="20" onchange="recalcBulkCards()" />
        </div>
        <div>
          <label>Kargo</label>
          <select id="bulkDefaultCarrier" onchange="recalcBulkCards()">
            <option value="hepsijet">Hepsijet</option>
            <option value="dhl">DHL</option>
            <option value="aras">Aras</option>
          </select>
        </div>
        <div>
          <label>Ödeme</label>
          <select id="bulkDefaultPayment" onchange="recalcBulkCards()">
            <option value="card">Kredi kartı</option>
            <option value="eft">Havale / EFT</option>
            <option value="term30">30 gün vadeli</option>
            <option value="term60">60 gün vadeli</option>
            <option value="installment2">2 taksit</option>
          </select>
        </div>
      </div>

      <div class="bulkAddBar">
        <button type="button" class="primary bulkAddBtn" onclick="openTsoftProductPicker()">
          + T-Soft'tan Ürün Ekle
        </button>
        <div class="bulkAddHint">Ürün ara, seç → listeye otomatik eklenir ve hesaplanır.</div>
      </div>

      <div class="bulkCardsHead" id="bulkCardsHead" style="display:none">
        <span id="bulkCardsCount">0 ürün</span>
        <div class="bulkCardsActions">
          <button type="button" class="ghost" onclick="copyBulkResults()">Sonuçları kopyala</button>
          <button type="button" class="ghost" onclick="downloadBulkResultsCsv()">CSV indir</button>
          <button type="button" class="ghost dangerBtn" onclick="clearBulkCards()">Tümünü temizle</button>
        </div>
      </div>

      <div id="bulkCardList" class="bulkCardList">
        <div class="bulkEmptyState" id="bulkEmptyState">
          <div class="bulkEmptyIcon">📦</div>
          <b>Henüz ürün eklenmedi</b>
          <span>Yukarıdaki “T-Soft'tan Ürün Ekle” butonuna basıp ürün ara, seç. Seçtiğin her ürün burada listelenir ve satış fiyatı otomatik hesaplanır.</span>
        </div>
      </div>
    </div>
  </div>

  <div class="tsoftModalBackdrop hide" id="tsoftProductModal" aria-hidden="true">
    <div class="tsoftModal" role="dialog" aria-modal="true" aria-labelledby="tsoftProductModalTitle">
      <div class="tsoftModalHead">
        <div>
          <h3 id="tsoftProductModalTitle">T-Soft Ürün Ara</h3>
          <p>Ürün adı, barkod veya ürün kodu yaz. Seçtiğin ürün toplu listeye eklenir.</p>
        </div>
        <button type="button" class="tsoftModalClose" onclick="closeTsoftProductPicker()" aria-label="Kapat">×</button>
      </div>
      <div class="tsoftSearchBar">
        <input id="tsoftProductSearchInput" type="search" autocomplete="off" placeholder="Örn: HP 135A, PYRZ0017162, barkod..." />
        <button type="button" class="primary" onclick="searchTsoftProductsNow()">Ara</button>
      </div>
      <div class="tsoftModalStatus" id="tsoftProductSearchStatus">Aramak için en az 2 karakter yaz.</div>
      <div class="tsoftProductResults" id="tsoftProductResults"></div>
    </div>
  </div>

<div class="panel modulePanel" id="priceModule">
    <div class="steps">
      <div class="stepTab active" data-tab="1">1. Maliyet</div>
      <div class="stepTab" data-tab="2">2. Kâr</div>
      <div class="stepTab" data-tab="3">3. Kargo</div>
      <div class="stepTab" data-tab="4">4. Sonuç</div>
    </div>

    <div class="body">
      <section class="screen active" data-screen="1">
        <div class="grid">
          <div>
            <label>Ürün maliyeti</label>
            <input id="cost" type="text" inputmode="decimal" data-format-number autocomplete="off" placeholder="Örn: 10.000" />
          </div>
          <div>
            <label>Para birimi</label>
            <div class="choice" id="currencyChoice">
              <button type="button" data-currency="TL" class="active">TL</button>
              <button type="button" data-currency="USD">USD</button>
              <button type="button" data-currency="EUR">EUR</button>
            </div>
          </div>
        </div>
        <div style="margin-top:18px">
          <label>Ödeme şekli</label>
          <div class="choice" id="paymentChoice">
            <button type="button" data-pay="card" class="active">Kredi kartı</button>
            <button type="button" data-pay="eft">Havale / EFT</button>
            <button type="button" data-pay="term30">30 gün vadeli</button>
            <button type="button" data-pay="term60">60 gün vadeli</button>
            <button type="button" data-pay="installment2">2 taksit</button>
          </div>
        </div>
        <div class="error" id="err1"></div>
        <div class="actions">
          <span></span>
          <button class="primary" onclick="goProfit()">Devam</button>
        </div>
      </section>

      <section class="screen" data-screen="2">
        <div class="grid">
          <div>
            <label>Net kâr oranı</label>
            <input id="profit" type="number" min="5" max="100" step="1" value="20" />
          </div>
        </div>
        <div class="error" id="err2"></div>
        <div class="actions">
          <button class="ghost" onclick="showScreen(1)">Geri</button>
          <button class="primary" onclick="goDesiOrResult()">Devam</button>
        </div>
      </section>

      <section class="screen" data-screen="3">
        <div style="margin-bottom:16px">
          <label>Kargo firması</label>
          <div class="choice" id="cargoChoice">
            <button type="button" data-carrier="hepsijet" class="active">Hepsijet</button>
            <button type="button" data-carrier="dhl">DHL</button>
            <button type="button" data-carrier="aras">Aras</button>
          </div>
        </div>

        <div class="mode">
          <button type="button" id="modeKnown" class="active" onclick="setDesiMode('known')">Desi biliyorum</button>
          <button type="button" id="modeMeasure" onclick="setDesiMode('measure')">Ölçüden hesapla</button>
        </div>

        <div id="knownBox" class="grid">
          <div>
            <label>Desi</label>
            <input id="desiKnown" type="text" inputmode="decimal" autocomplete="off" placeholder="Örn: 6" oninput="updateDesiPreview()" />
          </div>
        </div>

        <div id="measureBox" class="grid3 hide">
          <div>
            <label>En / cm</label>
            <input id="widthCm" type="text" inputmode="decimal" autocomplete="off" oninput="updateDesiPreview()" />
          </div>
          <div>
            <label>Boy / cm</label>
            <input id="lengthCm" type="text" inputmode="decimal" autocomplete="off" oninput="updateDesiPreview()" />
          </div>
          <div>
            <label>Yükseklik / cm</label>
            <input id="heightCm" type="text" inputmode="decimal" autocomplete="off" oninput="updateDesiPreview()" />
          </div>
        </div>

        <div class="desiPreview">
          <div class="mini"><span>Desi</span><b id="desiOut">-</b></div>
          <div class="mini"><span>Kargo maliyeti</span><b id="cargoOut">-</b></div>
        </div>

        <div class="error" id="err3"></div>
        <div class="actions">
          <button class="ghost" onclick="showScreen(2)">Geri</button>
          <button class="primary" onclick="goResult()">Devam</button>
        </div>
      </section>

      <section class="screen" data-screen="4">
        <div class="cards">
          <div class="card bold"><div class="k">KDV hariç satış</div><div class="v copyable" id="saleEx" title="Kopyala">-</div><div class="tiny tlSub" id="saleExTL"></div></div>
          <div class="card"><div class="k">KDV dahil satış</div><div class="v copyable" id="saleInc" title="Kopyala">-</div><div class="tiny tlSub" id="saleIncTL"></div></div>
          <div class="card good"><div class="k">Net kâr</div><div class="v" id="netProfit">-</div><div class="tiny tlSub" id="netProfitTL"></div></div>
          <div class="card warn"><div class="k">Kargo maliyeti</div><div class="v" id="cargoCost">-</div><div class="tiny" id="cargoNote"></div></div>
          <div class="card"><div class="k">Müşteriye yansıtılacak kargo</div><div class="v" id="customerCargo">-</div><div class="tiny" id="customerCargoNote"></div></div>
          <div class="card bold"><div class="k">Müşteriden alınacak KDV hariç toplam</div><div class="v copyable" id="customerTotalEx" title="Kopyala">-</div><div class="tiny tlSub" id="customerTotalExTL"></div></div>
          <div class="card warn"><div class="k">Kesinti / indirim / vade farkı</div><div class="v" id="profitLoss">-</div><div class="tiny" id="profitLossNote"></div></div>
        </div>

        <div class="resultExtras">
          <div class="extraBox">
            <div class="extraTitle">Diğer para birimleri</div>
            <div class="currencyResultGrid" id="altCurrencyResults"></div>
          </div>
          <div class="extraBox paymentNote">
            <div class="extraTitle">Ödeme notu</div>
            <div id="paymentImpactRows"></div>
          </div>
        </div>

        <div class="saleQuestion">
          <div class="q">Bu fiyattan satıldı mı?</div>
          <div class="saleBtns">
            <button type="button" class="yes" onclick="saveSaleLog(true)">Satıldı</button>
            <button type="button" class="no" onclick="saveSaleLog(false)">Satılmadı</button>
          </div>
          <div class="logInfo" id="logInfo"></div>
        </div>
        <div class="tableWrap">
          <table>
            <thead>
              <tr>
                <th>Kâr</th>
                <th>KDV hariç satış</th>
                <th>KDV dahil satış</th>
                <th>Net kâr</th>
                <th>Durum</th>
              </tr>
            </thead>
            <tbody id="rows"></tbody>
          </table>
        </div>
        <div class="actions resultActions">
          <button class="ghost" onclick="backFromResult()">Geri</button>
          <button class="ghost" onclick="openLabelWizard()">Kargo etiketi</button>
          <button class="primary" onclick="resetWizard()">Yeni hesap</button>
        </div>
      </section>
    </div>
  </div>

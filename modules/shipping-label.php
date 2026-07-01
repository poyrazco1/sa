<div class="panel modulePanel hide" id="labelModule">
    <div class="labelSteps">
      <div class="labelTab active" data-label-tab="1" onclick="showLabelScreen(1)">1. Müşteri</div>
      <div class="labelTab" data-label-tab="2" onclick="showLabelScreen(2)">2. Adres</div>
      <div class="labelTab" data-label-tab="3" onclick="showLabelScreen(3)">3. Kargo</div>
      <div class="labelTab" data-label-tab="4" onclick="showLabelScreen(4)">4. Yazdır</div>
      <div class="labelTab recordsTab" data-label-tab="5" onclick="showLabelScreen(5)">5. Kayıtlar</div>
    </div>

    <div class="body">
      <section class="labelScreen active" data-label-screen="1">
        <div class="customerSearchBox">
          <div>
            <label>Müşteri ara</label>
            <div class="searchLine">
              <input id="customerSearch" type="text" autocomplete="off" placeholder="Alıcı adı, firma adı veya telefon ile ara" />
              <button type="button" class="primary" onclick="searchCustomers()">Ara</button>
            </div>
          </div>
          <div class="customerActions">
            <button type="button" class="ghost" onclick="startManualCustomer()">Manuel yeni alıcı</button>
            <button type="button" class="ghost" onclick="showNewCustomerBox()">Müşteri ekle</button>
            <button type="button" class="ghost" onclick="document.getElementById('customerCsvFile').click()">CSV içe aktar</button>
            <button type="button" class="ghost" onclick="exportCustomersCsv()">CSV dışa aktar</button>
            <input id="customerCsvFile" type="file" accept=".csv,text/csv" hidden onchange="importCustomersCsv(this)" />
          </div>
        </div>
        <div class="logInfo" id="customerCsvInfo"></div>

        <div class="customerBox hide" id="newCustomerBox">
          <div class="customerFormMode" id="customerFormMode"></div>
          <div class="grid">
            <div>
              <label>Alıcı adı</label>
              <input id="newCustomerName" type="text" autocomplete="off" />
            </div>
            <div>
              <label>Firma adı</label>
              <input id="newCustomerCompany" type="text" autocomplete="off" />
            </div>
          </div>
          <div class="grid" style="margin-top:14px">
            <div>
              <label>Telefon</label>
              <input id="newCustomerPhone" type="text" autocomplete="off" />
            </div>
            <div>
              <label>Kısa not</label>
              <input id="newCustomerNote" type="text" autocomplete="off" />
            </div>
          </div>

          <div style="margin-top:14px">
            <label>Müşteri adresi</label>
            <textarea id="newCustomerRawAddress" placeholder="Adres, telefon, posta kodu, il / ilçe tek parça yapıştırılabilir."></textarea>
            <div class="labelHint">Müşteriyi eklerken adresini de kayıt ediyoruz. Sonraki gönderilerde aynı adres seçilince otomatik gelir.</div>
          </div>

          <div class="grid3" style="margin-top:14px">
            <div><label>Mahalle</label><input id="newCustomerMahalle" type="text" autocomplete="off" /></div>
            <div><label>Sokak / Cadde</label><input id="newCustomerStreet" type="text" autocomplete="off" /></div>
            <div><label>No</label><input id="newCustomerNo" type="text" autocomplete="off" /></div>
          </div>
          <div class="grid3" style="margin-top:14px">
            <div><label>Posta kodu</label><input id="newCustomerPostcode" type="text" inputmode="numeric" autocomplete="off" /></div>
            <div><label>İlçe</label><input id="newCustomerCounty" type="text" autocomplete="off" /></div>
            <div><label>İl</label><input id="newCustomerCity" type="text" autocomplete="off" /></div>
          </div>
          <div style="margin-top:14px">
            <label>Ek adres / açıklama</label>
            <input id="newCustomerExtra" type="text" autocomplete="off" />
          </div>

          <div class="actions">
            <button type="button" class="ghost" onclick="parseNewCustomerAddress(false)">Adresi ayır</button>
            <button type="button" class="primary" id="customerSaveBtn" onclick="createCustomerFromBox()">Müşteriyi oluştur</button>
          </div>
        </div>

        <div class="customerListPanel">
          <div class="customerListTitle">
            <b>Müşteri listesi</b>
            <span>Burada sadece müşteriler görünür. Kargo kayıtları ayrı Kayıtlar sekmesinde.</span>
          </div>
          <div id="customerResults" class="customerResults"></div>
        </div>

        <div class="selectedCustomer hide" id="selectedCustomerBox">
          <div>
            <span>Seçili müşteri</span>
            <b id="selectedCustomerText">-</b>
          </div>
          <div class="addressDecision">
            <button type="button" class="primary" onclick="useSameCustomerAddress()">Aynı adres</button>
            <button type="button" class="ghost" onclick="useDifferentCustomerAddress()">Farklı adres</button>
            <button type="button" class="ghost dangerBtn" onclick="clearSelectedCustomer()">Seçimi kaldır</button>
          </div>
        </div>

        <div class="error" id="labelErr0"></div>
        <div class="actions">
          <span></span>
          <button class="primary" onclick="goLabelAddress()">Devam</button>
        </div>
      </section>

      <section class="labelScreen" data-label-screen="2">
        <div class="grid">
          <div>
            <label>Adresi yapıştır</label>
            <textarea id="rawAddress" placeholder="Adres, telefon, posta kodu, il / ilçe tek parça yapıştırılabilir."></textarea>
            <div class="labelHint">Yapıştırınca mahalle, sokak, no, posta kodu, ilçe, il ve telefon otomatik ayrılır.</div>
          </div>
          <div>
            <label>Alıcı adı / firma adı</label>
            <input id="shipRecipient" type="text" autocomplete="off" placeholder="Alıcı adı veya firma" />
            <label style="margin-top:12px">Telefon</label>
            <input id="shipPhone" type="text" autocomplete="off" placeholder="Telefon" />
          </div>
        </div>

        <div class="grid3" style="margin-top:16px">
          <div><label>Mahalle</label><input id="shipMahalle" type="text" autocomplete="off" /></div>
          <div><label>Sokak / Cadde</label><input id="shipStreet" type="text" autocomplete="off" /></div>
          <div><label>No</label><input id="shipNo" type="text" autocomplete="off" /></div>
        </div>
        <div class="grid3" style="margin-top:16px">
          <div><label>Posta kodu</label><input id="shipPostcode" type="text" inputmode="numeric" autocomplete="off" /></div>
          <div><label>İlçe</label><input id="shipCounty" type="text" autocomplete="off" /></div>
          <div><label>İl</label><input id="shipCity" type="text" autocomplete="off" /></div>
        </div>
        <div style="margin-top:16px">
          <label>Ek adres / açıklama</label>
          <input id="shipExtra" type="text" autocomplete="off" placeholder="Kat, daire, bina adı vb." />
        </div>

        <div class="error" id="labelErr1"></div>
        <div class="actions">
          <button class="ghost" onclick="showLabelScreen(1)">Geri</button>
          <button class="ghost" onclick="parseShippingAddress(false)">Adresi ayır</button>
          <button class="primary" onclick="goLabelCargo()">Devam</button>
        </div>
      </section>

      <section class="labelScreen" data-label-screen="3">
        <div>
          <label>Kargo firması</label>
          <div class="choice" id="labelCarrierChoice">
            <button type="button" data-label-carrier="dhl" class="active">DHL</button>
            <button type="button" data-label-carrier="aras">Aras</button>
          </div>
        </div>
        <div style="margin-top:18px">
          <label>Logo tipi</label>
          <div class="logoChoice" id="labelLogoMode">
            <button type="button" data-logo-mode="color" class="active">Renkli</button>
            <button type="button" data-logo-mode="bw">Siyah beyaz</button>
          </div>
        </div>
        <div style="margin-top:18px">
          <label>Ödeme tipi</label>
          <div class="logoChoice" id="labelPayChoice">
            <button type="button" data-label-pay="seller" class="active">Satıcı ödemeli</button>
            <button type="button" data-label-pay="buyer">Alıcı ödemeli</button>
          </div>
        </div>
        <div style="margin-top:18px">
          <label>Yazdırma ölçüsü</label>
          <div class="paperChoice" id="paperChoice">
            <button type="button" data-paper="a4">A4</button>
            <button type="button" data-paper="a5">A5</button>
            <button type="button" data-paper="10x10">10×10 etiket</button>
            <button type="button" data-paper="15x10" class="active">15×10 etiket</button>
          </div>
        </div>
        <div class="grid" style="margin-top:18px">
          <div>
            <label>Fatura numarası / referans / sipariş no</label>
            <input id="shipRef" type="text" autocomplete="off" placeholder="Örn: FTR20260001" />
          </div>
          <div>
            <label>Kaç parça?</label>
            <input id="labelPieces" type="number" min="1" max="20" value="1" />
          </div>
        </div>

        <div class="error" id="labelErr2"></div>
        <div class="actions">
          <button class="ghost" onclick="showLabelScreen(2)">Geri</button>
          <button class="primary" onclick="goLabelPreview()">Önizle</button>
        </div>
      </section>

      <section class="labelScreen" data-label-screen="4">
        <div class="labelPrintSummary" id="labelPrintSummary"></div>
        <div class="labelPreviewWrap">
          <div id="labelPreviewStack" class="labelPreviewStack"></div>
        </div>
        <div class="actions">
          <button class="ghost" onclick="showLabelScreen(3)">Geri</button>
          <button class="ghost" onclick="resetShippingLabelForm()">Yeni kayıt</button>
          <button class="ghost" onclick="showLabelScreen(5)">Kayıtlı etiketler</button>
          <button class="primary" onclick="saveShippingLabelOnly()">Kaydet</button>
          <button class="primary" onclick="printShippingLabel()">Yazdır ve kaydet</button>
        </div>
        <div class="logInfo" id="labelDbInfo"></div>
      </section>
      <section class="labelScreen" data-label-screen="5">
        <div class="splitNotice">
          <b>Kargo etiketi kayıtları</b>
          <span>Burası sadece kayıtlı kargo etiketleri içindir. Müşteri listesi 1. adımda ayrı durur.</span>
        </div>

        <div class="labelRecordsPanel">
          <div class="labelRecordsHead">
            <div>
              <h3>Kayıtlı kargo etiketleri</h3>
              <p>Kaydedilen etiketleri buradan tekrar yazdırabilir, düzenleyebilir veya silebilirsin.</p>
            </div>
            <div class="labelRecordSearch">
              <input id="labelRecordSearch" type="text" autocomplete="off" placeholder="Firma, müşteri, telefon, ref ara" />
              <button type="button" class="ghost" onclick="loadShippingLabelRecords()">Yenile</button>
            </div>
          </div>

          <div class="bulkPrintPanel">
            <label class="selectAllLine">
              <input id="bulkSelectAllLabels" type="checkbox" onchange="toggleAllShippingRecords(this.checked)" />
              <span>Tümünü seç</span>
            </label>
            <div id="bulkPrintSelectedInfo">Seçili etiket yok.</div>
            <button type="button" class="primary" onclick="bulkPrintSelectedLabels()">Seçilenleri yazdır</button>
          </div>

          <div id="shippingRecordsList" class="shippingRecordsList">
            <div class="labelHint">Kayıtlar yükleniyor...</div>
          </div>
        </div>

        <div class="actions">
          <button class="ghost" onclick="showLabelScreen(1)">Müşteri listesine dön</button>
          <button class="primary" onclick="resetShippingLabelForm()">Yeni etiket oluştur</button>
        </div>
      </section>
    </div>
  </div>

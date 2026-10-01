// --- Data Source (from index.php) ---
const serverProducts = Array.isArray(window.__POS_PRODUCTS__)
  ? window.__POS_PRODUCTS__
  : [];
const activeVariants = Array.isArray(window.__POS_VARIANTS__)
  ? window.__POS_VARIANTS__
  : [];
const receiptTemplate =
  window.__RECEIPT_TEMPLATE__ && typeof window.__RECEIPT_TEMPLATE__ === "object"
    ? window.__RECEIPT_TEMPLATE__
    : {};
const printTemplates =
  window.__PRINT_TEMPLATES__ && typeof window.__PRINT_TEMPLATES__ === "object"
    ? window.__PRINT_TEMPLATES__
    : {};
const CART_STORAGE_KEY = "pos_cart_v2";

const variantsById = {};
activeVariants.forEach((v) => {
  variantsById[String(v.id)] = {
    id: String(v.id),
    name: v.name || `Varian ${v.id}`,
    color: v.color || "#4F46E5",
  };
});

// --- State ---
let products = serverProducts.map((p) => {
  const normalizedVariantPrices = {};
  if (p.variant_prices && typeof p.variant_prices === "object") {
    Object.entries(p.variant_prices).forEach(([key, item]) => {
      const variantId = String((item && item.id) || key);
      const master = variantsById[variantId] || {};
      normalizedVariantPrices[variantId] = {
        id: variantId,
        price: Number((item && item.price) || 0),
        name: (item && item.name) || master.name || `Varian ${variantId}`,
        color: (item && item.color) || master.color || "#4F46E5",
      };
    });
  }
  return {
    id: Number(p.id),
    code: String(p.code || ""),
    name: String(p.name || ""),
    category: String(p.category || "Lainnya"),
    price: Number(p.price || 0),
    stock: Number(p.stock || 0),
    image: p.image || "P",
    variant_prices: normalizedVariantPrices,
  };
});
let cart = [];
let discount = 0;
let payment = 0;
let downpayment = 0;
let paymentMethod = "cash";
let paymentNote = "";
let activeCashierVariantId = "";
let activeCategory = "Semua";
let activeSearch = "";
let currentDraftId = 0;
let todayTransactions = [];
let saveDraftByShortcut = false;

// --- DOM Elements ---
const productsGrid = document.getElementById("productsGrid");
const cartItems = document.getElementById("cartItems");
const emptyCartState = document.getElementById("emptyCartState");
const subtotalEl = document.getElementById("subtotalAmount");
const totalEl = document.getElementById("totalAmount");
const changeEl = document.getElementById("changeAmount");
const paymentInput = document.getElementById("paymentAmount");
const paymentAmountLabel = document.getElementById("paymentAmountLabel");
const paymentMethodLabel = document.getElementById("paymentMethodLabel");
const paymentMethodNoteEl = document.getElementById("paymentMethodNote");
const discountInput = document.getElementById("discountInput");
const discountType = document.getElementById("discountType");
const discountAmountEl = document.getElementById("discountAmount");
const downpaymentInput = document.getElementById("downpaymentInput");
const downpaymentAmountEl = document.getElementById("downpaymentAmount");
const checkoutBtn = document.getElementById("checkoutBtn");
const saveDraftBtn = document.getElementById("saveDraftBtn");
const printReceiptBtn = document.getElementById("printReceiptBtn");
const openPaymentModalBtn = document.getElementById("openPaymentModalBtn");
const paymentModal = document.getElementById("paymentModal");
const closePaymentModalBtn = document.getElementById("closePaymentModalBtn");
const cancelPaymentModalBtn = document.getElementById("cancelPaymentModalBtn");
const confirmCheckoutBtn = document.getElementById("confirmCheckoutBtn");
const paymentAmountModal = document.getElementById("paymentAmountModal");
const paymentNoteInput = document.getElementById("paymentNoteInput");
const paymentModalValidation = document.getElementById("paymentModalValidation");
const checkoutValidationNotice = document.getElementById(
  "checkoutValidationNotice",
);
const paymentModalTotal = document.getElementById("paymentModalTotal");
const paymentModalPaid = document.getElementById("paymentModalPaid");
const paymentModalChange = document.getElementById("paymentModalChange");
const paymentMethodCards = Array.from(
  document.querySelectorAll(".payment-method-card"),
);
const searchInput = document.getElementById("searchInput");
const clearCartBtn = document.getElementById("clearCartBtn");
const newTransactionBtn = document.getElementById("newTransactionBtn");
const customerNameInput = document.getElementById("customerName");
const printArea = document.getElementById("printArea");
const categoriesWrapper = document.getElementById("categoriesWrapper");
const cashierVariantSelect = document.getElementById("cashierVariantSelect");
const receiptModal = document.getElementById("receiptModal");
const receiptContent = document.getElementById("receiptContent");
const variantValidationNotice = document.getElementById(
  "variantValidationNotice",
);
const toastWrap = document.getElementById("toastWrap");
const transactionList = document.getElementById("transactionList");
const transactionStatusFilter = document.getElementById(
  "transactionStatusFilter",
);

// --- Helpers ---
const formatRupiah = (number) =>
  new Intl.NumberFormat("id-ID", {
    style: "currency",
    currency: "IDR",
    minimumFractionDigits: 0,
  }).format(number || 0);

function getPaymentMethodLabel(method = paymentMethod) {
  return method === "transfer" ? "Transfer Rek" : "Tunai";
}

function setInlineValidation(element, message = "") {
  if (!element) return;
  element.textContent = message;
  element.style.display = message ? "block" : "none";
}

function computeTransactionSummary() {
  const subtotal = cart.reduce(
    (sum, item) => sum + item.price * item.quantity,
    0,
  );
  const discountValue =
    parseFloat(discountInput && discountInput.value ? discountInput.value : 0) ||
    0;
  const discountTypeValue =
    typeof discountType !== "undefined" && discountType && discountType.value
      ? discountType.value
      : "nominal";
  const discountCalc =
    discountTypeValue === "percent"
      ? Math.floor(subtotal * (discountValue / 100))
      : discountValue;
  const totalBeforeDP = Math.max(0, subtotal - discountCalc);
  const dpValue =
    parseFloat(
      downpaymentInput && downpaymentInput.value ? downpaymentInput.value : 0,
    ) || 0;
  const dpCalc = Math.max(0, Math.min(dpValue, totalBeforeDP));
  const total = Math.max(0, totalBeforeDP - dpCalc);
  const enteredPayment = parseFloat(paymentInput && paymentInput.value) || 0;
  const paidTotal = dpCalc + enteredPayment;
  const change =
    paymentMethod === "cash" ? Math.max(0, paidTotal - total) : 0;

  return {
    subtotal,
    discountCalc,
    dpCalc,
    total,
    enteredPayment,
    paidTotal,
    change,
  };
}

function syncPaymentMethodSummary() {
  if (paymentAmountLabel) {
    paymentAmountLabel.textContent =
      paymentMethod === "transfer"
        ? "Nominal Transfer (Rp)"
        : "Tunai Dibayar (Rp)";
  }
  if (paymentMethodLabel) {
    paymentMethodLabel.textContent = getPaymentMethodLabel();
  }
  if (paymentMethodNoteEl) {
    paymentMethodNoteEl.textContent =
      paymentNote.trim() || "Belum ada keterangan pembayaran.";
  }
}

function syncPaymentMethodCards() {
  paymentMethodCards.forEach((card) => {
    card.classList.toggle("active", card.dataset.method === paymentMethod);
  });
}

function syncPaymentModalSummary() {
  const { total, paidTotal, change } = computeTransactionSummary();
  if (paymentModalTotal) paymentModalTotal.textContent = formatRupiah(total);
  if (paymentModalPaid) paymentModalPaid.textContent = formatRupiah(paidTotal);
  if (paymentModalChange)
    paymentModalChange.textContent = formatRupiah(change);
}

function openPaymentModal() {
  if (!paymentModal) return;
  if (paymentAmountModal) {
    paymentAmountModal.value = paymentInput ? paymentInput.value : "";
  }
  if (paymentNoteInput) {
    paymentNoteInput.value = paymentNote;
  }
  syncPaymentMethodCards();
  syncPaymentModalSummary();
  setInlineValidation(paymentModalValidation, "");
  paymentModal.classList.add("active");
}

function closePaymentModal() {
  if (!paymentModal) return;
  paymentModal.classList.remove("active");
  setInlineValidation(paymentModalValidation, "");
}

function showToast(message, type = "info") {
  if (!toastWrap) return;
  const div = document.createElement("div");
  div.className = `toast ${type}`;
  div.textContent = message;
  toastWrap.appendChild(div);
  setTimeout(() => {
    div.style.opacity = "0";
    div.style.transform = "translateY(-4px)";
  }, 2600);
  setTimeout(() => div.remove(), 3200);
}

function escapeHtml(value) {
  return String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}

function applyPrintTemplate(templateHtml, placeholders) {
  let output = templateHtml;
  Object.entries(placeholders).forEach(([key, val]) => {
    const token = `{{${key}}}`;
    output = output.split(token).join(String(val ?? ""));
  });
  return output;
}

const productById = (id) => products.find((p) => p.id === Number(id));

function getVariantOption(product, variantId) {
  if (!variantId || !product) return null;
  const vid = String(variantId);
  const specific = product.variant_prices ? product.variant_prices[vid] : null;
  if (specific) return specific;

  const master = variantsById[vid];
  if (!master) return null;
  return {
    id: vid,
    name: master.name,
    color: master.color,
    // Varian belum disetting di produk ini: pakai harga jual utama, bukan Rp 0.
    price: Number(product.price || 0),
    fallback: true,
  };
}

function getPriceForProduct(product, variantId) {
  const variant = getVariantOption(product, variantId);
  if (variant && Number(variant.price) >= 0) return Number(variant.price);
  return Number(product.price || 0);
}

function cartKey(productId, variantId) {
  return `${productId}::${variantId || "normal"}`;
}

function renderCategoryButtons() {
  const cats = [
    ...new Set(products.map((p) => p.category).filter(Boolean)),
  ].sort((a, b) => a.localeCompare(b));
  const allCats = ["Semua", ...cats];
  categoriesWrapper.innerHTML = allCats
    .map(
      (cat) =>
        `<button class="category-btn ${cat === activeCategory ? "active" : ""}" data-cat="${cat}">${cat}</button>`,
    )
    .join("");

  categoriesWrapper.querySelectorAll(".category-btn").forEach((btn) => {
    btn.addEventListener("click", (e) => {
      activeCategory = e.currentTarget.dataset.cat || "Semua";
      filterAndRenderProducts();
      renderCategoryButtons();
    });
  });
}

function renderCashierVariantOptions() {
  cashierVariantSelect.innerHTML = "";
  activeVariants.forEach((v) => {
    const opt = document.createElement("option");
    opt.value = String(v.id);
    opt.textContent = v.name;
    cashierVariantSelect.appendChild(opt);
  });

  if (activeVariants.length === 0) {
    activeCashierVariantId = "";
    return;
  }

  const stillValid = activeVariants.some(
    (v) => String(v.id) === String(activeCashierVariantId),
  );
  if (!stillValid) {
    // Tanpa opsi bawaan, default jatuh ke varian "Normal" dari master bila ada.
    const normal = activeVariants.find((v) =>
      String(v.name || "")
        .trim()
        .toLowerCase()
        .startsWith("normal"),
    );
    activeCashierVariantId = String((normal || activeVariants[0]).id);
  }
  cashierVariantSelect.value = activeCashierVariantId;
}

function filterProducts() {
  const term = activeSearch.toLowerCase();
  return products.filter((p) => {
    const matchSearch =
      p.name.toLowerCase().includes(term) ||
      p.code.toLowerCase().includes(term);
    const matchCat =
      activeCategory === "Semua" || p.category === activeCategory;
    return matchSearch && matchCat;
  });
}

function filterAndRenderProducts() {
  renderProducts(filterProducts());
}

function renderProducts(productsToRender) {
  productsGrid.innerHTML = "";

  if (productsToRender.length === 0) {
    productsGrid.innerHTML =
      '<div style="grid-column: 1/-1; text-align: center; color: var(--text-muted); padding: 40px;">Produk tidak ditemukan</div>';
    return;
  }

  productsToRender.forEach((product) => {
    const card = document.createElement("div");
    card.className = "product-card";
    card.onclick = () => addToCart(product);

    const activeVariant = getVariantOption(product, activeCashierVariantId);
    const cardPrice = getPriceForProduct(product, activeCashierVariantId);
    const variantChip = activeVariant
      ? `<div class="variant-chip" style="color:${activeVariant.color}; border-color:${activeVariant.color}66; background:${activeVariant.color}1A;">
                    <span class="variant-dot" style="background:${activeVariant.color};"></span>
                    ${activeVariant.fallback ? `${activeVariant.name} (belum disetting)` : activeVariant.name}
               </div>`
      : "";

    card.innerHTML = `
            <div class="product-stock">${product.stock} tersisa</div>
            <div class="product-image-placeholder" style="font-size: 26px;">${product.image}</div>
            <div class="product-title">${product.name}</div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">${product.code}</div>
            <div class="product-price">${formatRupiah(cardPrice)}</div>
            ${variantChip}
        `;
    productsGrid.appendChild(card);
  });
}

function buildVariantSelectOptions(product, selectedVariantId) {
  let opts = `<option value="" ${!selectedVariantId ? "selected" : ""}>Harga Normal</option>`;
  activeVariants.forEach((master) => {
    const variantId = String(master.id);
    const selected = String(selectedVariantId) === variantId ? "selected" : "";
    opts += `<option value="${variantId}" ${selected}>${master.name}</option>`;
  });
  return opts;
}

function renderCart() {
  localStorage.setItem(CART_STORAGE_KEY, JSON.stringify(cart));
  const itemsToRemove = cartItems.querySelectorAll(".cart-item");
  itemsToRemove.forEach((item) => item.remove());

  if (cart.length === 0) {
    emptyCartState.style.display = "flex";
    updateSummary();
    return;
  }

  emptyCartState.style.display = "none";

  cart.forEach((item, index) => {
    const product = productById(item.id);
    const hasVariantOptions = !!product && activeVariants.length > 0;
    const variantWarning = item.variant_unset
      ? '<div class="variant-warning">Varian ini belum disetting di master produk. Memakai harga jual utama.</div>'
      : "";
    const cartItem = document.createElement("div");
    cartItem.className = "cart-item";

    cartItem.innerHTML = `
            <div class="cart-item-details">
                <div class="cart-item-title">${item.name}</div>
                <div class="cart-item-price">${formatRupiah(item.price)}</div>
                ${
                  hasVariantOptions
                    ? `<div class="cart-item-variant">
                            <select onchange="changeCartItemVariant(${index}, this.value)">
                                ${buildVariantSelectOptions(product, item.variant_id)}
                            </select>
                            ${variantWarning}
                        </div>`
                    : ""
                }
            </div>
            <div class="qty-controls">
                <button class="qty-btn" onclick="updateQty(${index}, -1)">-</button>
                <input type="number" class="qty-input" value="${item.quantity}" onchange="setQty(${index}, this.value)">
                <button class="qty-btn" onclick="updateQty(${index}, 1)">+</button>
            </div>
            <div class="cart-item-subtotal">${formatRupiah(item.price * item.quantity)}</div>
            <button class="btn-remove-item" onclick="removeFromCart(${index})" title="Hapus">
                <i data-feather="x"></i>
            </button>
        `;

    cartItems.insertBefore(cartItem, emptyCartState);
  });

  feather.replace();
  updateSummary();
}

function updateSummary() {
  const { subtotal, discountCalc, dpCalc, total, change, enteredPayment } =
    computeTransactionSummary();
  discount = discountCalc;
  downpayment = dpCalc;
  subtotalEl.innerText = formatRupiah(subtotal);
  if (discountAmountEl) discountAmountEl.innerText = "- " + formatRupiah(discountCalc);
  if (downpaymentAmountEl) downpaymentAmountEl.innerText = "- " + formatRupiah(dpCalc);
  totalEl.innerText = formatRupiah(total);
  payment = enteredPayment;

  const hasUnsetVariant = cart.some((item) => item.variant_unset);
  if (paymentMethod === "cash" && enteredPayment > 0 && total > 0) {
    changeEl.innerText = formatRupiah(change);
    changeEl.style.color = "var(--emerald)";
  } else {
    changeEl.innerText = "Rp 0";
    changeEl.style.color = "var(--text-muted)";
  }

  checkoutBtn.disabled = cart.length === 0;
  saveDraftBtn.disabled = cart.length === 0;
  if (hasUnsetVariant) {
    variantValidationNotice.style.display = "block";
    variantValidationNotice.textContent =
      "Ada item yang belum punya harga untuk varian ini, jadi memakai harga jual utama. Set harga di master produk bila perlu.";
  } else {
    variantValidationNotice.style.display = "none";
    variantValidationNotice.textContent = "";
  }
  syncPaymentMethodSummary();
  syncPaymentModalSummary();
  printReceiptBtn.disabled = cart.length === 0;
}

function formatDateTime(dateTimeRaw) {
  if (!dateTimeRaw) return "-";
  const dt = new Date(dateTimeRaw.replace(" ", "T"));
  if (Number.isNaN(dt.getTime())) return dateTimeRaw;
  return dt.toLocaleTimeString("id-ID", { hour: "2-digit", minute: "2-digit" });
}

function renderTransactionList() {
  if (!transactionList || !transactionStatusFilter) return;
  const filter = transactionStatusFilter
    ? transactionStatusFilter.value
    : "all";
  const rows = todayTransactions.filter((tx) =>
    filter === "all" ? true : tx.status === filter,
  );
  if (!rows.length) {
    transactionList.innerHTML =
      '<div style="font-size:12px;color:var(--text-muted);text-align:center;padding:8px 0;">Belum ada transaksi.</div>';
    return;
  }

  transactionList.innerHTML = rows
    .map((tx) => {
      const name =
        tx.customer_name && tx.customer_name.trim() !== ""
          ? tx.customer_name
          : "Pelanggan Umum";
      const badge =
        tx.status === "paid"
          ? '<span class="tx-badge paid">Sudah Bayar</span>'
          : '<span class="tx-badge pending">Belum Bayar</span>';
      const actionBtn =
        tx.status === "pending"
          ? `<button class="btn-mini-load" onclick="loadDraftTransaction(${tx.id})">Lanjutkan</button>`
          : "";
      return `
                <div class="tx-item">
                    <div class="meta">
                        <div class="name">${name}</div>
                        <div class="sub">${tx.invoice_no} • ${formatDateTime(tx.transaction_at)} • ${formatRupiah(tx.total)}</div>
                    </div>
                    <div style="display:flex;align-items:center;gap:6px;flex-shrink:0;">
                        ${badge}
                        ${actionBtn}
                    </div>
                </div>
            `;
    })
    .join("");
}

async function fetchTodayTransactions() {
  if (!transactionList) return;
  try {
    const res = await fetch("checkout_actions.php?action=list_today");
    const data = await res.json();
    if (data.success) {
      todayTransactions = data.transactions || [];
      renderTransactionList();
    }
  } catch (_e) {
    // silent
  }
}

window.loadDraftTransaction = async (id) => {
  try {
    const res = await fetch(`checkout_actions.php?action=detail&id=${id}`);
    const data = await res.json();
    if (!data.success) throw new Error(data.message || "Gagal mengambil draft");
    const tx = data.transaction;
    const items = data.items || [];

    cart = items.map((it) => ({
      id: Number(it.product_id || 0),
      code: it.product_code || "",
      name: it.product_name || "",
      stock: 999999,
      quantity: Number(it.qty || 1),
      variant_id: it.variant_id ? String(it.variant_id) : "",
      variant_name: it.variant_name || "",
      variant_unset: false,
      price: Number(it.price || 0),
      key: cartKey(
        Number(it.product_id || 0),
        it.variant_id ? String(it.variant_id) : "",
      ),
    }));
    currentDraftId = Number(tx.id || 0);
    if (customerNameInput) customerNameInput.value = tx.customer_name || "";
    // Set discount and downpayment inputs when loading draft
    if (discountInput) discountInput.value = typeof tx.discount !== 'undefined' ? Number(tx.discount) : 0;
    if (downpaymentInput) downpaymentInput.value = typeof tx.downpayment !== 'undefined' ? Number(tx.downpayment) : 0;
    paymentMethod = tx.payment_method === "transfer" ? "transfer" : "cash";
    paymentNote = tx.payment_note || "";
    // For payment input show the extra paid amount beyond DP if transaction already paid
    if (paymentInput) {
      if (tx.status === 'paid') {
        const paidAmt = Number(tx.paid_amount || 0);
        const dpAmt = Number(tx.downpayment || 0);
        paymentInput.value = Math.max(0, paidAmt - dpAmt) || '';
      } else {
        paymentInput.value = '';
      }
    }
    renderCart();
    showToast("Draft transaksi dimuat ke keranjang.", "success");
  } catch (err) {
    showToast(err.message, "error");
  }
};

async function submitTransaction(actionType) {
  const { subtotal, discountCalc, dpCalc, total, enteredPayment } =
    computeTransactionSummary();
  discount = discountCalc;
  const grossTotal = Math.max(0, subtotal - discountCalc);
  const paidTotal =
    actionType === "pay"
      ? dpCalc + enteredPayment
      : actionType === "save_draft"
        ? dpCalc
        : 0;
  const change =
    actionType === "pay" && paymentMethod === "cash"
      ? Math.max(0, paidTotal - total)
      : 0;

  const response = await fetch("checkout_actions.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      action: actionType,
      draft_id: currentDraftId || 0,
      customer_name: customerNameInput ? customerNameInput.value.trim() : "",
      subtotal,
      discount: discountCalc,
      downpayment: dpCalc,
      total: grossTotal,
      paid_amount: paidTotal,
      change_amount: actionType === "pay" ? change : 0,
      payment_method: paymentMethod,
      payment_note: paymentNote.trim(),
      items: cart.map((item) => ({
        id: item.id,
        code: item.code,
        name: item.name,
        variant_id: item.variant_id || "",
        variant_name: item.variant_name || "",
        price: item.price,
        quantity: item.quantity,
      })),
    }),
  });
  const result = await response.json();
  if (!response.ok || !result.success) {
    throw new Error(result.message || "Gagal memproses transaksi");
  }
  return { result, subtotal, total: grossTotal, paid: paidTotal, change };
}

function normalizeSavedCartItem(item) {
  const product = productById(item.id);
  if (!product) return null;
  const quantity = Number(item.quantity || 0);
  if (quantity <= 0) return null;

  const variantId = item.variant_id ? String(item.variant_id) : "";
  const variantOpt = getVariantOption(product, variantId);
  const price = getPriceForProduct(product, variantId);

  return {
    id: product.id,
    code: product.code,
    name: product.name,
    stock: product.stock,
    quantity: Math.min(quantity, product.stock),
    variant_id: variantOpt ? variantId : "",
    variant_name: variantOpt ? variantOpt.name : "",
    variant_unset: !!(variantOpt && variantOpt.fallback),
    price,
    key: cartKey(product.id, variantOpt ? variantId : ""),
  };
}

function loadCartFromStorage() {
  try {
    const saved = JSON.parse(localStorage.getItem(CART_STORAGE_KEY)) || [];
    if (!Array.isArray(saved)) return [];
    return saved
      .map((item) => normalizeSavedCartItem(item))
      .filter((item) => item && item.quantity > 0);
  } catch (_e) {
    return [];
  }
}

// --- Cart Actions ---
window.addToCart = (product) => {
  if (product.stock <= 0) {
    showToast("Stok produk habis!", "error");
    return;
  }

  const variantId = activeCashierVariantId || "";
  const variant = getVariantOption(product, variantId);
  const appliedVariantId = variant ? String(variantId) : "";
  const lineKey = cartKey(product.id, appliedVariantId);
  const price = getPriceForProduct(product, appliedVariantId);
  const existingItemIndex = cart.findIndex((item) => item.key === lineKey);

  if (existingItemIndex > -1) {
    if (cart[existingItemIndex].quantity >= product.stock) {
      showToast("Melebihi batas stok yang tersedia!", "error");
      return;
    }
    cart[existingItemIndex].quantity += 1;
  } else {
    cart.push({
      id: product.id,
      code: product.code,
      name: product.name,
      stock: product.stock,
      quantity: 1,
      variant_id: appliedVariantId,
      variant_name: variant ? variant.name : "",
      variant_unset: !!(variant && variant.fallback),
      price,
      key: lineKey,
    });
  }

  renderCart();
  setTimeout(() => {
    cartItems.scrollTop = cartItems.scrollHeight;
  }, 50);
};

window.changeCartItemVariant = (index, variantId) => {
  const item = cart[index];
  const product = productById(item.id);
  if (!item || !product) return;

  const selectedVariantId = variantId ? String(variantId) : "";
  const variant = getVariantOption(product, selectedVariantId);
  const appliedVariantId = variant ? selectedVariantId : "";
  const newKey = cartKey(product.id, appliedVariantId);
  const duplicateIndex = cart.findIndex(
    (x, i) => i !== index && x.key === newKey,
  );

  if (duplicateIndex > -1) {
    const totalQty = cart[duplicateIndex].quantity + item.quantity;
    cart[duplicateIndex].quantity = Math.min(totalQty, product.stock);
    cart.splice(index, 1);
    renderCart();
    return;
  }

  item.variant_id = appliedVariantId;
  item.variant_name = variant ? variant.name : "";
  item.variant_unset = !!(variant && variant.fallback);
  item.price = getPriceForProduct(product, appliedVariantId);
  item.key = newKey;
  renderCart();
};

window.updateQty = (index, delta) => {
  const newQty = cart[index].quantity + delta;
  if (newQty > 0) {
    const product = productById(cart[index].id);
    if (product && newQty > product.stock) {
      showToast("Melebihi batas stok yang tersedia!", "error");
      return;
    }
    cart[index].quantity = newQty;
  } else {
    removeFromCart(index);
    return;
  }
  renderCart();
};

window.setQty = (index, value) => {
  const newQty = parseInt(value, 10);
  if (isNaN(newQty) || newQty <= 0) {
    removeFromCart(index);
  } else {
    const product = productById(cart[index].id);
    if (product && newQty > product.stock) {
      cart[index].quantity = product.stock;
      showToast("Qty disesuaikan dengan sisa stok!", "info");
    } else {
      cart[index].quantity = newQty;
    }
  }
  renderCart();
};

window.removeFromCart = (index) => {
  cart.splice(index, 1);
  renderCart();
};

clearCartBtn.addEventListener("click", () => {
  if (
    cart.length > 0 &&
    confirm("Mulai transaksi baru? Keranjang saat ini akan dikosongkan.")
  ) {
    startNewTransaction();
  }
});

if (newTransactionBtn) {
  newTransactionBtn.addEventListener("click", () => {
    if (cart.length === 0) {
      startNewTransaction();
      return;
    }
    if (confirm("Mulai transaksi baru? Keranjang saat ini akan dikosongkan.")) {
      startNewTransaction();
    }
  });
}

// --- Checkout & Print ---
function generateReceiptHTML(total, paid, change) {
  const date = new Date().toLocaleString("id-ID");
  const refId = "TRX-" + Date.now().toString().slice(-6);
  const headerAlign = receiptTemplate.header_align || "center";
  const showLogo = String(receiptTemplate.show_logo || "0") === "1";
  const showStoreInfo = String(receiptTemplate.show_store_info || "1") === "1";
  const showItemCode = String(receiptTemplate.show_item_code || "0") === "1";
  const paperWidth = Number(receiptTemplate.paper_width_mm || 58);
  const fontSize = Number(receiptTemplate.font_size_px || 12);
  const storeName = receiptTemplate.store_name || "Toko POS Kita";
  const storeAddress = receiptTemplate.store_address || "";
  const storePhone = receiptTemplate.store_phone || "";
  const bankAccount = receiptTemplate.bank_account || "";
  const logoUrl = receiptTemplate.logo_url || "";
  const headerText = receiptTemplate.header_text || "";
  const footerText =
    receiptTemplate.footer_text || "Terima kasih sudah berbelanja";
  const paymentMethodLabelText = getPaymentMethodLabel();
  const paymentNoteText = paymentNote.trim();
  const thermalWidth = paperWidth === 80 ? "76mm" : "58mm";

  const itemsHTML = cart
    .map((item) => {
      const lineName = item.variant_name
        ? `${item.name} (${item.variant_name})`
        : item.name;
      const lineCode = showItemCode
        ? `<div style="font-size:10px;color:#666;">${item.code || ""}</div>`
        : "";
      return `
                <div style="display: flex; justify-content: space-between; margin-bottom: 4px; font-size: 12px;">
                    <span>${lineName}${lineCode}</span>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 12px; color: #555;">
                    <span>${item.quantity} x ${formatRupiah(item.price)}</span>
                    <span>${formatRupiah(item.price * item.quantity)}</span>
                </div>
            `;
    })
    .join("");

  // compute subtotal, sisa bayar and ensure {{total}} maps to subtotal (before discount and downpayment)
  const subtotal = cart.reduce((sum, item) => sum + item.price * item.quantity, 0);
  const discountVal = Number(discount || 0);
  const dpVal = Number(downpayment || 0);
  const sisaBayar = Math.max(0, subtotal - discountVal - dpVal);

  if (printTemplates.nota && String(printTemplates.nota).trim() !== "") {
    const statusText =
      paid >= total && total > 0 ? "Sudah Bayar" : "Belum Bayar";
    const logoImg = logoUrl
      ? `<img src="${escapeHtml(logoUrl)}" alt="Logo" style="max-width:120px;max-height:60px;object-fit:contain;">`
      : "";
    return applyPrintTemplate(printTemplates.nota, {
      store_name: escapeHtml(storeName),
      store_address: escapeHtml(storeAddress),
      store_phone: escapeHtml(storePhone),
      bank_account: escapeHtml(bankAccount),
      logo_url: escapeHtml(logoUrl),
      logo_img: logoImg,
      invoice_no: escapeHtml(refId),
      transaction_at: escapeHtml(date),
      customer_name: escapeHtml(
        customerNameInput
          ? customerNameInput.value.trim() || "Pelanggan Umum"
          : "Pelanggan Umum",
      ),
      items_rows: itemsHTML,
      // total should be the subtotal (before discount and downpayment)
      total: escapeHtml(formatRupiah(subtotal)),
      discount: escapeHtml(formatRupiah(discountVal || 0)),
      downpayment: escapeHtml(formatRupiah(dpVal || 0)),
      sisa_bayar: escapeHtml(formatRupiah(sisaBayar)),
      paid_amount: escapeHtml(formatRupiah(paid)),
      change_amount: escapeHtml(formatRupiah(change)),
      payment_method: escapeHtml(paymentMethodLabelText),
      payment_note: escapeHtml(paymentNoteText),
      status: escapeHtml(statusText),
      footer_text: escapeHtml(footerText),
    });
  }

  return `
        <div style="width: ${thermalWidth}; margin: 0 auto; font-family: 'Courier New', Courier, monospace; color: #000; padding: 10px; font-size: ${fontSize}px;">
            <div style="text-align: ${headerAlign}; margin-bottom: 15px;">
                ${showLogo && logoUrl ? `<img src="${logoUrl}" alt="logo" style="max-width:120px;max-height:60px;object-fit:contain;margin-bottom:6px;">` : ""}
                ${showStoreInfo ? `<h3 style="margin: 0; font-size: 16px;">${storeName}</h3>` : ""}
                ${showStoreInfo && storeAddress ? `<p style="margin: 0; font-size: 12px; color: #555;">${storeAddress}</p>` : ""}
                ${showStoreInfo && storePhone ? `<p style="margin: 0; font-size: 12px; color: #555;">${storePhone}</p>` : ""}
                ${showStoreInfo && bankAccount ? `<p style="margin: 0; font-size: 12px; color: #555;">Rek: ${bankAccount}</p>` : ""}
                ${headerText ? `<p style="margin: 3px 0 0; font-size: 11px; color: #555;">${headerText}</p>` : ""}
                <div style="border-bottom: 1px dashed #000; margin: 10px 0;"></div>
                <div style="display: flex; justify-content: space-between; font-size: 10px;">
                    <span>${date}</span>
                    <span>${refId}</span>
                </div>
                <div style="border-bottom: 1px dashed #000; margin: 10px 0;"></div>
            </div>
            <div style="margin-bottom: 15px;">${itemsHTML}</div>
            <div style="border-bottom: 1px dashed #000; margin: 10px 0;"></div>
      <div style="display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 5px;">
        <span>Diskon</span>
        <span>${formatRupiah(discount || 0)}</span>
      </div>
      <div style="display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 5px;">
        <span>Uang Muka</span>
        <span>${formatRupiah(downpayment || 0)}</span>
      </div>
      <div style="display: flex; justify-content: space-between; font-size: 14px; font-weight: bold; margin-bottom: 5px;">
        <span>Total</span>
        <span>${formatRupiah(subtotal)}</span>
      </div>
      <div style="display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 5px;">
        <span>Sisa Bayar</span>
        <span>${formatRupiah(sisaBayar)}</span>
      </div>
      <div style="display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 5px;">
        <span>${paymentMethodLabelText}</span>
        <span>${formatRupiah(paid)}</span>
      </div>
      ${paymentNoteText ? `<div style="font-size: 11px; color: #555; margin-bottom: 5px;">Ket: ${escapeHtml(paymentNoteText)}</div>` : ""}
            <div style="display: flex; justify-content: space-between; font-size: 12px;">
                <span>Kembali</span>
                <span>${formatRupiah(change)}</span>
            </div>
            <div style="border-bottom: 1px dashed #000; margin: 15px 0;"></div>
            <div style="text-align: center; font-size: 12px;">
                <p style="margin: 0;">${footerText}</p>
                <p style="margin: 0;">Barang yang sudah dibeli tidak dapat ditukar</p>
            </div>
        </div>
    `;
}

async function processCheckoutPayment() {
  const { total, enteredPayment, paidTotal, change } = computeTransactionSummary();
  if (cart.length === 0) {
    setInlineValidation(checkoutValidationNotice, "Keranjang masih kosong.");
    closePaymentModal();
    return;
  }
  if (enteredPayment <= 0) {
    const message = `Nominal pembayaran ${getPaymentMethodLabel().toLowerCase()} wajib diisi.`;
    setInlineValidation(paymentModalValidation, message);
    setInlineValidation(checkoutValidationNotice, message);
    return;
  }
  if (paidTotal < total) {
    const message =
      paymentMethod === "transfer"
        ? "Nominal transfer masih kurang dari total yang harus dibayar."
        : "Nominal tunai masih kurang dari total yang harus dibayar.";
    setInlineValidation(paymentModalValidation, message);
    setInlineValidation(checkoutValidationNotice, message);
    return;
  }

  const original = confirmCheckoutBtn.innerHTML;
  confirmCheckoutBtn.disabled = true;
  confirmCheckoutBtn.innerText = "Menyimpan...";

  try {
    const { result } = await submitTransaction("pay");
    closePaymentModal();
    setInlineValidation(checkoutValidationNotice, "");
    printArea.innerHTML = generateReceiptHTML(total, paidTotal, change);
    window.print();

    setTimeout(() => {
      cart = [];
      currentDraftId = 0;
      paymentInput.value = "";
      paymentMethod = "cash";
      paymentNote = "";
      if (customerNameInput) customerNameInput.value = "";
      renderCart();
      showToast(
        `Transaksi tersimpan (${result.invoice_no || "OK"})`,
        "success",
      );
    }, 500);
  } catch (err) {
    setInlineValidation(
      paymentModalValidation,
      `Gagal checkout: ${err.message}`,
    );
  } finally {
    confirmCheckoutBtn.disabled = false;
    confirmCheckoutBtn.innerHTML = original;
    updateSummary();
  }
}

checkoutBtn.addEventListener("click", async () => {
  if (cart.length === 0 || checkoutBtn.disabled) return;

  checkoutBtn.disabled = true;
  checkoutBtn.innerText = "Menyimpan...";

  try {
    const { result, total, paid, change } = await submitTransaction("pay");

    printArea.innerHTML = generateReceiptHTML(total, paid, change);
    window.print();

    setTimeout(() => {
      cart = [];
      currentDraftId = 0;
      paymentInput.value = "";
      if (customerNameInput) customerNameInput.value = "";
      renderCart();
      showToast(
        `Transaksi tersimpan (${result.invoice_no || "OK"})`,
        "success",
      );
    }, 500);
  } catch (err) {
    showToast(`Gagal checkout: ${err.message}`, "error");
  } finally {
    checkoutBtn.innerHTML =
      '<div class="checkout-btn-content"><span>Bayar Sekarang</span><span class="shortcut-hint-dark">Enter ↵</span></div>';
    updateSummary();
  }
});

saveDraftBtn.addEventListener("click", async () => {
  if (cart.length === 0) return;
  const original = saveDraftBtn.innerHTML;
  saveDraftBtn.disabled = true;
  saveDraftBtn.innerText = "Menyimpan...";
  try {
    const { result } = await submitTransaction("save_draft");
    currentDraftId = Number(result.transaction_id || 0);
    const draftNo = result.invoice_no || `Draft #${currentDraftId}`;
    showToast(`Berhasil simpan transaksi belum bayar (${draftNo}).`, "success");
    // Setelah berhasil simpan draft, reset keranjang untuk memulai transaksi baru
    startNewTransaction();
  } catch (err) {
    showToast(`Gagal simpan draft: ${err.message}`, "error");
  } finally {
    saveDraftByShortcut = false;
    saveDraftBtn.innerHTML = original;
    updateSummary();
  }
});

function triggerSaveDraftShortcut() {
  if (!saveDraftBtn || saveDraftBtn.disabled) {
    showToast(
      "Keranjang kosong. Tambahkan item dulu untuk simpan belum bayar.",
      "info",
    );
    return;
  }
  saveDraftByShortcut = true;
  saveDraftBtn.click();
}

function startNewTransaction() {
  cart = [];
  currentDraftId = 0;
  paymentInput.value = "";
  paymentMethod = "cash";
  paymentNote = "";
  if (paymentAmountModal) paymentAmountModal.value = "";
  if (paymentNoteInput) paymentNoteInput.value = "";
  if (customerNameInput) customerNameInput.value = "";
  // Diskon & uang muka wajib ikut direset, kalau tidak nilainya terbawa ke transaksi berikutnya.
  if (discountInput) discountInput.value = 0;
  if (discountType) discountType.value = "nominal";
  if (downpaymentInput) downpaymentInput.value = 0;
  discount = 0;
  downpayment = 0;
  setInlineValidation(checkoutValidationNotice, "");
  setInlineValidation(paymentModalValidation, "");
  renderCart();
  showToast("Keranjang baru siap. Silakan input transaksi baru.", "success");
}

window.openReceiptModal = () => {
  if (cart.length === 0) return;
  const { subtotal, discountCalc, dpCalc, enteredPayment, change } =
    computeTransactionSummary();
  const grossTotal = Math.max(0, subtotal - discountCalc);
  receiptContent.innerHTML = generateReceiptHTML(
    grossTotal,
    dpCalc + enteredPayment,
    change,
  );
  receiptModal.classList.add("active");
  feather.replace();
};

window.closeReceiptModal = () => {
  receiptModal.classList.remove("active");
};

window.doPrint = () => {
  if (cart.length === 0) return;
  const { subtotal, discountCalc, dpCalc, enteredPayment, change } =
    computeTransactionSummary();
  const grossTotal = Math.max(0, subtotal - discountCalc);
  printArea.innerHTML = generateReceiptHTML(
    grossTotal,
    dpCalc + enteredPayment,
    change,
  );
  window.print();
};

// --- Events ---
if (openPaymentModalBtn) {
  openPaymentModalBtn.addEventListener("click", openPaymentModal);
}
if (closePaymentModalBtn) {
  closePaymentModalBtn.addEventListener("click", closePaymentModal);
}
if (cancelPaymentModalBtn) {
  cancelPaymentModalBtn.addEventListener("click", closePaymentModal);
}
if (paymentAmountModal) {
  paymentAmountModal.addEventListener("input", () => {
    if (paymentInput) paymentInput.value = paymentAmountModal.value;
    setInlineValidation(paymentModalValidation, "");
    setInlineValidation(checkoutValidationNotice, "");
    updateSummary();
  });
}
if (paymentNoteInput) {
  paymentNoteInput.addEventListener("input", () => {
    paymentNote = paymentNoteInput.value || "";
    syncPaymentMethodSummary();
    setInlineValidation(paymentModalValidation, "");
  });
}
paymentMethodCards.forEach((card) => {
  card.addEventListener("click", () => {
    paymentMethod = card.dataset.method === "transfer" ? "transfer" : "cash";
    syncPaymentMethodCards();
    syncPaymentMethodSummary();
    updateSummary();
  });
});
if (confirmCheckoutBtn) {
  confirmCheckoutBtn.addEventListener("click", processCheckoutPayment);
}
if (paymentModal) {
  paymentModal.addEventListener("click", (e) => {
    if (e.target === paymentModal) closePaymentModal();
  });
}
if (checkoutBtn) {
  checkoutBtn.addEventListener(
    "click",
    (e) => {
      if (cart.length === 0 || checkoutBtn.disabled) return;
      e.preventDefault();
      e.stopImmediatePropagation();
      setInlineValidation(checkoutValidationNotice, "");
      openPaymentModal();
    },
    true,
  );
}
paymentInput.addEventListener("input", () => {
  if (paymentAmountModal && document.activeElement !== paymentAmountModal) {
    paymentAmountModal.value = paymentInput.value;
  }
  setInlineValidation(checkoutValidationNotice, "");
  updateSummary();
});
if (discountInput) {
  discountInput.addEventListener("input", updateSummary);
}
if (discountType) {
  discountType.addEventListener("change", updateSummary);
}
if (downpaymentInput) {
  downpaymentInput.addEventListener("input", updateSummary);
}
searchInput.addEventListener("input", (e) => {
  activeSearch = e.target.value || "";
  filterAndRenderProducts();
});
cashierVariantSelect.addEventListener("change", (e) => {
  activeCashierVariantId = e.target.value || "";
  filterAndRenderProducts();
});
if (transactionStatusFilter) {
  transactionStatusFilter.addEventListener("change", renderTransactionList);
}

const draftIdFromUrl = Number(
  new URLSearchParams(window.location.search).get("draft_id") || 0,
);
if (draftIdFromUrl > 0) {
  window.loadDraftTransaction(draftIdFromUrl);
}

document.addEventListener("keydown", (e) => {
  if (e.key === "F2") {
    e.preventDefault();
    searchInput.focus();
  }
  if ((e.ctrlKey || e.metaKey) && String(e.key || "").toLowerCase() === "s") {
    e.preventDefault();
    triggerSaveDraftShortcut();
    return;
  }
  if (e.key === "F9") {
    e.preventDefault();
    // Tanpa dialog native supaya kasir bisa langsung lanjut ke transaksi berikutnya.
    startNewTransaction();
    return;
  }
  if (e.key === "Enter") {
    if (paymentModal && paymentModal.classList.contains("active")) {
      if (document.activeElement !== paymentNoteInput) {
        e.preventDefault();
        processCheckoutPayment();
      }
      return;
    }
    if (document.activeElement === searchInput) {
      if (
        productsGrid.children.length > 0 &&
        productsGrid.children[0].className === "product-card"
      ) {
        productsGrid.children[0].click();
        searchInput.value = "";
        activeSearch = "";
        filterAndRenderProducts();
      }
    } else if (!checkoutBtn.disabled) {
      e.preventDefault();
      checkoutBtn.click();
    }
  }
});

// --- Init ---
renderCashierVariantOptions();
renderCategoryButtons();
cart = loadCartFromStorage();
filterAndRenderProducts();
renderCart();

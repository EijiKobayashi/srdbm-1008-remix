const form = document.querySelector('#replace');
const loading = document.querySelector('#loading');
const upload = document.querySelector('#sql_upload');
const uploadButton = document.querySelector('#upload-sql');
const uploadLabel = document.querySelector('#upload-label');
const uploadStatus = document.querySelector('#upload-status');
const dropZone = document.querySelector('#drop-zone');
const sqlSelect = document.querySelector('#sql_file');
const chunkedSqlFile = document.querySelector('#chunked_sql_file');
const sourceUrl = document.querySelector('#source_url');
const destinationUrl = document.querySelector('#destination_url');
const forceHttps = document.querySelector('#force_https');
const sourcePrefix = document.querySelector('#source_prefix');
const destinationPrefix = document.querySelector('#destination_prefix');
const inspectionEmpty = document.querySelector('#inspection-empty');
const inspectionLoading = document.querySelector('#inspection-loading');
const inspectionResults = document.querySelector('#inspection-results');
const dryRunButton = form?.querySelector('button[value="dry-run"]');
const dryRunStateNode = document.querySelector('#dry-run-wordpress-state');

let dryRunState = null;
if (dryRunStateNode) {
    try {
        dryRunState = JSON.parse(dryRunStateNode.textContent || 'null');
    } catch (error) {
        dryRunState = null;
    }
}

let inspectionRequest = 0;

const element = (tag, className = '', text = '') => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text) node.textContent = text;
    return node;
};

const resetInspection = (message = 'SQLのアップロード後に、検出したWordPress設定を表示します。') => {
    inspectionRequest++;
    inspectionEmpty.textContent = message;
    inspectionEmpty.className = 'border border-dashed border-wp-line bg-[#fafafa] p-5 text-sm text-wp-muted';
    inspectionEmpty.classList.remove('hidden');
    inspectionLoading.classList.add('hidden');
    inspectionLoading.classList.remove('flex');
    inspectionResults.classList.add('hidden');
    inspectionResults.replaceChildren();
    if (dryRunButton) dryRunButton.disabled = Boolean(upload?.files?.[0]);
};

const settingBlock = (title, description, count) => {
    const block = element('section', 'border-t border-wp-line pt-5 first:border-t-0 first:pt-0');
    const heading = element('div', 'mb-3 flex flex-wrap items-center gap-2');
    heading.append(element('h3', 'font-semibold', title));
    heading.append(element('span', 'rounded-full bg-[#f0f0f1] px-2 py-0.5 text-[11px] text-wp-muted', `${count}件`));
    block.append(heading, element('p', 'mb-4 text-xs leading-5 text-wp-muted', description));
    return block;
};

const hostnameFromUrl = (value) => {
    try {
        return new URL(value).hostname;
    } catch (error) {
        return '';
    }
};

const emailWithDomain = (email, domain) => {
    const separator = email.lastIndexOf('@');
    return separator > 0 && domain ? `${email.slice(0, separator)}@${domain}` : email;
};

const domainFromEmail = (email) => {
    const separator = email.lastIndexOf('@');
    return separator > 0 ? email.slice(separator + 1) : '';
};

const normalizedEmailDomain = (value) => {
    const candidate = value.trim().replace(/^@/, '');
    if (!candidate || /[\s\/:]/.test(candidate)) return '';
    try {
        const parsed = new URL(`https://${candidate}`);
        return parsed.hostname && parsed.hostname === parsed.host ? parsed.hostname : '';
    } catch (error) {
        return '';
    }
};

const renderInspection = (inspection) => {
    inspectionResults.replaceChildren();

    if (dryRunState) {
        const notice = element('div', 'border-l-4 border-wp-blue bg-[#f0f6fc] p-4');
        notice.append(
            element('p', 'font-semibold text-wp-ink', 'ドライラン適用内容（変更予定）'),
            element('p', 'mt-1 text-xs leading-5 text-wp-muted', '以下はドライランで使用した設定です。「変換する」では同じ内容を適用します。変更する場合は「最初から」やり直してください。')
        );
        inspectionResults.append(notice);
    }

    const prefixes = Array.isArray(inspection.table_prefixes) ? inspection.table_prefixes : [];
    const prefixBlock = settingBlock('テーブル接頭辞', 'SQLから検出した正確な接頭辞です。変更する場合は、この値を「変更前」に設定してください。', prefixes.length);
    const prefixList = element('div', 'space-y-2');
    if (prefixes.length === 0) {
        prefixList.append(element('p', 'text-sm text-wp-muted', 'WordPressのテーブル接頭辞は検出されませんでした。'));
    }
    prefixes.forEach((item, index) => {
        const row = element('div', 'flex flex-col gap-3 rounded-sm bg-[#f6f7f7] p-3 sm:flex-row sm:items-center');
        const valueWrap = element('div', 'min-w-0 flex-1');
        valueWrap.append(element('code', 'break-all text-sm font-semibold text-wp-ink', item.value));
        valueWrap.append(element('p', 'mt-1 text-xs text-wp-muted', `WordPress基本テーブル ${Number(item.tables || 0)}件で検出`));
        const applyButton = element('button', 'btn secondary shrink-0', '変更前へ設定');
        applyButton.type = 'button';
        applyButton.addEventListener('click', () => {
            sourcePrefix.value = item.value;
            destinationPrefix.focus();
        });
        row.append(valueWrap, applyButton);
        prefixList.append(row);
        if (index === 0 && sourcePrefix && !sourcePrefix.value) sourcePrefix.placeholder = item.value;
    });
    prefixBlock.append(prefixList);

    const domainTables = Array.isArray(inspection.domain_tables) ? inspection.domain_tables : [];
    const selectedDomainTables = new Set(Array.isArray(dryRunState?.domain_tables) ? dryRunState.domain_tables : []);
    const domainMapping = inspection.domain_mapping || {};
    const domainBlock = settingBlock('ドメイン置換対象テーブル', 'チェックを外したテーブルでは、URL・ホスト名・メールアドレスのドメインを置換しません。', domainTables.length);
    const domainPresent = document.createElement('input');
    domainPresent.type = 'hidden';
    domainPresent.name = 'domain_tables_present';
    domainPresent.value = '1';
    const mapping = element('div', 'mb-4 grid gap-2 rounded-sm bg-[#f0f6fc] p-3 sm:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] sm:items-center');
    mapping.append(
        element('code', 'break-all text-xs font-semibold', domainMapping.source || ''),
        element('span', 'hidden text-wp-muted sm:block', '→'),
        element('code', 'break-all text-xs font-semibold', domainMapping.target || '')
    );
    const domainList = element('div', 'space-y-2');
    if (domainTables.length === 0) {
        domainList.append(element('p', 'text-sm text-wp-muted', 'ドメイン置換候補は検出されませんでした。'));
    }
    domainTables.forEach((item) => {
        const label = element('label', 'flex items-start gap-3 rounded-sm bg-[#f6f7f7] p-3');
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.name = 'domain_tables[]';
        checkbox.value = item.table;
        checkbox.checked = dryRunState ? selectedDomainTables.has(item.table) : true;
        checkbox.className = 'mt-0.5';
        const details = element('span', 'min-w-0 flex-1');
        details.append(element('code', 'block break-all text-sm font-semibold', item.table));
        const counts = [];
        if (Number(item.url || 0) > 0) counts.push(`URL ${Number(item.url).toLocaleString()}件`);
        if (Number(item.host || 0) > 0) counts.push(`ホスト ${Number(item.host).toLocaleString()}件`);
        if (Number(item.email || 0) > 0) counts.push(`メール ${Number(item.email).toLocaleString()}件`);
        details.append(element('small', 'mt-1 block text-xs text-wp-muted', `${counts.join(' / ')}（計 ${Number(item.total || 0).toLocaleString()}件）`));
        label.append(checkbox, details);
        domainList.append(label);
    });
    domainBlock.append(domainPresent, mapping, domainList);

    const emailRow = (item) => {
        const row = element('div', 'grid gap-2 rounded-sm bg-[#f6f7f7] p-3 sm:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] sm:items-center');
        const originalWrap = element('div');
        originalWrap.append(element('p', 'break-all text-sm font-medium', item.value));
        const emailDetails = [];
        if (Array.isArray(item.labels)) emailDetails.push(...item.labels);
        if (Number(item.occurrences || 0) > 0) emailDetails.push(`${Number(item.occurrences).toLocaleString()}箇所`);
        if (Array.isArray(item.tables) && item.tables.length > 0) emailDetails.push(item.tables.join(', '));
        originalWrap.append(element('p', 'mt-1 break-all text-xs text-wp-muted', emailDetails.join(' / ')));
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'email_original[]';
        hidden.value = item.value;
        const replacement = document.createElement('input');
        replacement.type = 'email';
        replacement.name = 'email_replacement[]';
        replacement.value = dryRunState?.email_replacements?.[item.value] || item.value;
        replacement.setAttribute('aria-label', `${item.value} の変更後メールアドレス`);
        row.append(hidden, originalWrap, element('span', 'hidden text-wp-muted sm:block', '→'), replacement);
        return row;
    };

    const adminEmails = Array.isArray(inspection.admin_emails) ? inspection.admin_emails : [];
    const adminEmailBlock = settingBlock('管理者メールアドレス', 'WordPressのサイト管理者設定と管理者ユーザーから検出しました。必要な場合だけ変更してください。', adminEmails.length);
    const adminEmailList = element('div', 'space-y-3');
    if (adminEmails.length === 0) {
        adminEmailList.append(element('p', 'text-sm text-wp-muted', '管理者メールアドレスは検出されませんでした。'));
    }
    adminEmails.forEach((item) => adminEmailList.append(emailRow(item)));
    adminEmailBlock.append(adminEmailList);

    const adminEmailValues = new Set(adminEmails.map((item) => item.value));
    const domainEmails = (Array.isArray(inspection.domain_emails) ? inspection.domain_emails : [])
        .filter((item) => !adminEmailValues.has(item.value));
    const domainEmailBlock = settingBlock('置換元ドメインのその他メール', '「@置換元ドメイン」に完全一致するメールを表示します。個別変更またはドメインの一括変換ができます。管理者メールとの重複は上の項目へまとめています。', domainEmails.length);
    const domainEmailList = element('div', 'space-y-3');
    if (domainEmails.length === 0) {
        domainEmailList.append(element('p', 'text-sm text-wp-muted', '管理者メール以外の対象メールは検出されませんでした。'));
    }
    domainEmails.forEach((item) => domainEmailList.append(emailRow(item)));
    const sourceEmailDomain = hostnameFromUrl(domainMapping.source || '');
    const targetEmailDomain = hostnameFromUrl(domainMapping.target || '');
    if (domainEmails.length > 0 && sourceEmailDomain && targetEmailDomain) {
        const bulkWrap = element('div', 'mb-4 rounded-sm border border-wp-line bg-[#fafafa] p-3');
        bulkWrap.append(element('p', 'mb-3 text-sm font-semibold text-wp-ink', 'メールドメインを一括変換'));
        const bulkContent = element('div', 'grid gap-2 sm:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)_auto] sm:items-center');
        const sourceDomainLabel = element('code', 'break-all rounded-sm bg-white px-3 py-2.5 text-sm', `@${sourceEmailDomain}`);
        const bulkDomainInput = document.createElement('input');
        bulkDomainInput.type = 'text';
        bulkDomainInput.placeholder = targetEmailDomain;
        bulkDomainInput.setAttribute('aria-label', '一括変換後のメールドメイン');
        bulkDomainInput.autocomplete = 'off';
        const dryRunDomains = new Set(domainEmails.map((item) => {
            const replacement = dryRunState?.email_replacements?.[item.value] || item.value;
            return domainFromEmail(replacement);
        }).filter(Boolean));
        bulkDomainInput.value = dryRunState && dryRunDomains.size === 1
            ? Array.from(dryRunDomains)[0]
            : targetEmailDomain;
        const bulkButton = element('button', 'btn secondary shrink-0', '一括変換を入力欄へ反映');
        bulkButton.type = 'button';
        const bulkStatus = element('p', 'mt-2 hidden text-xs font-medium text-wp-success');
        bulkStatus.setAttribute('role', 'status');
        bulkButton.addEventListener('click', () => {
            const replacementDomain = normalizedEmailDomain(bulkDomainInput.value);
            if (!replacementDomain) {
                bulkStatus.textContent = '変換後のドメインを正しく入力してください。例: example.com';
                bulkStatus.classList.remove('hidden', 'text-wp-success');
                bulkStatus.classList.add('text-wp-danger');
                bulkDomainInput.focus();
                return;
            }
            bulkDomainInput.value = replacementDomain;
            domainEmailList.querySelectorAll('input[name="email_replacement[]"]').forEach((input) => {
                input.value = emailWithDomain(input.value, replacementDomain);
            });
            bulkStatus.textContent = `${domainEmails.length.toLocaleString()}件の入力欄へ反映しました。必要に応じて個別に変更できます。`;
            bulkStatus.classList.remove('hidden', 'text-wp-danger');
            bulkStatus.classList.add('text-wp-success');
        });
        bulkContent.append(sourceDomainLabel, element('span', 'hidden text-center text-wp-muted sm:block', '→'), bulkDomainInput, bulkButton);
        bulkWrap.append(bulkContent, element('p', 'mt-2 text-xs text-wp-muted', '@より前は維持されます。変換後ドメインは任意に入力できます。'), bulkStatus);
        domainEmailBlock.append(bulkWrap);
    }
    domainEmailBlock.append(domainEmailList);

    const paths = Array.isArray(inspection.image_paths) ? inspection.image_paths : [];
    const pathBlock = settingBlock('画像パス', 'SQL内で検出したアップロード基点です。個別画像名ではなく、この基点をまとめて変更します。', paths.length);
    const pathList = element('div', 'space-y-3');
    if (paths.length === 0) {
        pathList.append(element('p', 'text-sm text-wp-muted', '画像パスは検出されませんでした。'));
    }
    paths.forEach((item) => {
        const row = element('div', 'grid gap-2 rounded-sm bg-[#f6f7f7] p-3 sm:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] sm:items-center');
        const originalWrap = element('div');
        originalWrap.append(element('p', 'break-all text-sm font-medium', item.value));
        originalWrap.append(element('p', 'mt-1 text-xs text-wp-muted', `検出 ${Number(item.occurrences || 0).toLocaleString()}箇所`));
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'image_path_original[]';
        hidden.value = item.value;
        const replacement = document.createElement('input');
        replacement.type = 'text';
        replacement.name = 'image_path_replacement[]';
        replacement.value = dryRunState?.image_path_replacements?.[item.value] || item.value;
        replacement.setAttribute('aria-label', `${item.value} の変更後画像パス`);
        row.append(hidden, originalWrap, element('span', 'hidden text-wp-muted sm:block', '→'), replacement);
        pathList.append(row);
    });
    pathBlock.append(pathList);

    const groups = Array.isArray(inspection.plugin_groups) ? inspection.plugin_groups : [];
    const pluginCount = new Set(groups.flatMap((group) => (group.plugins || []).map((plugin) => plugin.path))).size;
    const pluginBlock = settingBlock('プラグイン', 'SQL内で確認できたプラグインです。チェックありを有効、チェックなしを無効として変換します。', pluginCount);
    if (groups.length === 0) {
        pluginBlock.append(element('p', 'text-sm text-wp-muted', 'プラグイン設定は検出されませんでした。'));
    }
    groups.forEach((group) => {
        const selectedPlugins = new Set(Array.isArray(dryRunState?.plugins?.[group.id]) ? dryRunState.plugins[group.id] : []);
        const groupWrap = element('fieldset', 'mb-4 rounded-sm border border-wp-line p-4 last:mb-0');
        groupWrap.append(element('legend', 'px-1 text-sm font-semibold', group.label));
        const present = document.createElement('input');
        present.type = 'hidden';
        present.name = 'plugin_groups_present[]';
        present.value = group.id;
        groupWrap.append(present);
        const list = element('div', 'mt-2 grid gap-2 sm:grid-cols-2');
        (group.plugins || []).forEach((plugin) => {
            const label = element('label', 'flex items-start gap-2 rounded-sm bg-[#f6f7f7] p-3');
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.name = `plugins[${group.id}][]`;
            checkbox.value = plugin.path;
            checkbox.checked = dryRunState ? selectedPlugins.has(plugin.path) : Boolean(plugin.active);
            checkbox.className = 'mt-0.5';
            label.append(checkbox, element('span', 'break-all text-sm', plugin.path));
            list.append(label);
        });
        groupWrap.append(list);
        pluginBlock.append(groupWrap);
    });

    inspectionResults.append(prefixBlock, domainBlock, adminEmailBlock, domainEmailBlock, pathBlock, pluginBlock);
    inspectionEmpty.classList.add('hidden');
    inspectionLoading.classList.add('hidden');
    inspectionLoading.classList.remove('flex');
    inspectionResults.classList.remove('hidden');
    if (dryRunState) {
        inspectionResults.querySelectorAll('input, button').forEach((control) => {
            control.disabled = true;
        });
        if (dryRunButton) dryRunButton.disabled = true;
    }
};

const inspectSql = async (filename) => {
    if (!filename) {
        resetInspection();
        return;
    }
    const requestId = ++inspectionRequest;
    if (dryRunButton) dryRunButton.disabled = true;
    inspectionEmpty.classList.add('hidden');
    inspectionResults.classList.add('hidden');
    inspectionLoading.classList.remove('hidden');
    inspectionLoading.classList.add('flex');
    const data = new FormData();
    data.append('csrf_token', form.elements.csrf_token.value);
    data.append('filename', filename);
    data.append('source_url', sourceUrl?.value.trim() || '');
    let targetUrl = destinationUrl?.value.trim() || '';
    if (forceHttps?.checked) targetUrl = targetUrl.replace(/^http:\/\//i, 'https://');
    data.append('destination_url', targetUrl);
    try {
        const response = await fetch('?inspect_sql=1', { method: 'POST', body: data, credentials: 'same-origin' });
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.message || 'SQLの解析に失敗しました。');
        if (requestId === inspectionRequest) renderInspection(result.inspection || {});
    } catch (error) {
        if (requestId !== inspectionRequest) return;
        inspectionLoading.classList.add('hidden');
        inspectionLoading.classList.remove('flex');
        inspectionEmpty.textContent = error instanceof Error ? error.message : 'SQLの解析に失敗しました。';
        inspectionEmpty.className = 'border border-wp-danger bg-[#fcf0f1] p-5 text-sm text-wp-danger';
    } finally {
        if (requestId === inspectionRequest && dryRunButton) dryRunButton.disabled = Boolean(dryRunState);
    }
};

const setUploadSelection = (file) => {
    chunkedSqlFile.value = '';
    sqlSelect.value = '';
    uploadLabel.textContent = file.name;
    uploadStatus.textContent = `${file.name} を選択しました。「アップロードする」を押してください。`;
    uploadStatus.className = 'text-xs text-[#996800]';
    uploadButton.disabled = false;
    resetInspection('アップロード完了後にWordPress設定を解析します。');
};

upload?.addEventListener('change', () => {
    if (upload.files.length > 0) setUploadSelection(upload.files[0]);
});

sqlSelect?.addEventListener('change', async () => {
    upload.value = '';
    chunkedSqlFile.value = '';
    uploadButton.disabled = true;
    if (sqlSelect.value) {
        uploadLabel.textContent = 'SQLファイルを選択';
        uploadStatus.textContent = 'アップロード済みSQLを選択しています。ドライランへ進めます。';
        uploadStatus.className = 'text-xs font-medium text-wp-success';
        await inspectSql(sqlSelect.value);
    } else {
        uploadStatus.textContent = 'ファイルを選択するとアップロードできます。';
        uploadStatus.className = 'text-xs text-wp-muted';
        resetInspection();
    }
});

const refreshDomainInspection = async () => {
    const filename = chunkedSqlFile?.value || sqlSelect?.value || '';
    if (!filename || !sourceUrl?.value || !destinationUrl?.value) return;
    if (!sourceUrl.checkValidity() || !destinationUrl.checkValidity()) return;
    await inspectSql(filename);
};

sourceUrl?.addEventListener('change', refreshDomainInspection);
destinationUrl?.addEventListener('change', refreshDomainInspection);
forceHttps?.addEventListener('change', refreshDomainInspection);

for (const eventName of ['dragenter', 'dragover']) {
    dropZone?.addEventListener(eventName, (event) => {
        event.preventDefault();
        event.stopPropagation();
        dropZone.classList.add('border-wp-blue', 'bg-[#f6f7ff]');
    });
}

dropZone?.addEventListener('dragleave', (event) => {
    event.preventDefault();
    event.stopPropagation();
    dropZone.classList.remove('border-wp-blue', 'bg-[#f6f7ff]');
});

dropZone?.addEventListener('drop', (event) => {
    event.preventDefault();
    event.stopPropagation();
    dropZone.classList.remove('border-wp-blue', 'bg-[#f6f7ff]');

    const file = event.dataTransfer?.files?.[0];
    if (!file) return;
    if (!file.name.toLowerCase().endsWith('.sql')) {
        uploadLabel.textContent = '.sql ファイルを選択してください';
        uploadStatus.textContent = 'アップロードできるファイルは .sql のみです。';
        uploadStatus.className = 'text-xs font-medium text-wp-danger';
        return;
    }

    const transfer = new DataTransfer();
    transfer.items.add(file);
    upload.files = transfer.files;
    setUploadSelection(file);
});

for (const eventName of ['dragover', 'drop']) {
    document.addEventListener(eventName, (event) => event.preventDefault());
}

let chunkUploadRunning = false;

uploadButton?.addEventListener('click', async () => {
    const file = upload.files[0];
    if (!file || chunkUploadRunning) return;
    if (!file.name.toLowerCase().endsWith('.sql')) {
        uploadStatus.textContent = 'アップロードできるファイルは .sql のみです。';
        uploadStatus.className = 'text-xs font-medium text-wp-danger';
        return;
    }
    if (file.size <= 0 || file.size > 524288000) {
        uploadStatus.textContent = 'SQLファイルは1バイト以上500MB以下にしてください。';
        uploadStatus.className = 'text-xs font-medium text-wp-danger';
        return;
    }

    chunkUploadRunning = true;
    uploadButton.disabled = true;
    loading.classList.remove('hidden');
    loading.classList.add('flex');
    const loadingTitle = loading.querySelector('p');
    const chunkSize = 786432;
    const uploadId = Array.from(crypto.getRandomValues(new Uint8Array(16)), (byte) => byte.toString(16).padStart(2, '0')).join('');
    let offset = 0;

    let uploadedFilename = '';
    try {
        while (offset < file.size) {
            const end = Math.min(offset + chunkSize, file.size);
            const percent = Math.round(end / file.size * 100);
            const data = new FormData();
            data.append('csrf_token', form.elements.csrf_token.value);
            data.append('upload_id', uploadId);
            data.append('filename', file.name);
            data.append('offset', String(offset));
            data.append('is_last', end === file.size ? '1' : '0');
            data.append('chunk', file.slice(offset, end), 'chunk.part');
            loadingTitle.textContent = `SQLをアップロードしています（${percent}%）`;
            uploadStatus.textContent = `アップロード中… ${percent}%`;
            const response = await fetch('?chunk_upload=1', { method: 'POST', body: data, credentials: 'same-origin' });
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.message || 'アップロードに失敗しました。');
            offset = end;
            if (result.filename) {
                uploadedFilename = result.filename;
                chunkedSqlFile.value = result.filename;
                const option = new Option(`${result.filename} — アップロード完了`, result.filename, true, true);
                sqlSelect.add(option, 1);
            }
        }
        upload.value = '';
        uploadLabel.textContent = file.name;
        uploadStatus.textContent = 'アップロードが完了しました。置換条件を入力してドライランへ進んでください。';
        uploadStatus.className = 'text-xs font-medium text-wp-success';
        loadingTitle.textContent = 'WordPress設定を解析しています';
        await inspectSql(uploadedFilename);
    } catch (error) {
        chunkedSqlFile.value = '';
        uploadStatus.textContent = error instanceof Error ? error.message : 'アップロードに失敗しました。';
        uploadStatus.className = 'text-xs font-medium text-wp-danger';
        uploadButton.disabled = false;
    } finally {
        loading.classList.add('hidden');
        loading.classList.remove('flex');
        loadingTitle.textContent = 'SQLを処理しています';
        chunkUploadRunning = false;
    }
});

form?.addEventListener('submit', (event) => {
    if (upload.files[0] && !chunkedSqlFile.value) {
        event.preventDefault();
        uploadStatus.textContent = '先に「アップロードする」を押してください。';
        uploadStatus.className = 'text-xs font-medium text-wp-danger';
        uploadButton.focus();
        return;
    }
    if (!sqlSelect.value && !chunkedSqlFile.value) {
        event.preventDefault();
        uploadStatus.textContent = 'SQLファイルをアップロードするか、アップロード済みSQLを選択してください。';
        uploadStatus.className = 'text-xs font-medium text-wp-danger';
        document.querySelector('#files')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }
    loading.classList.remove('hidden');
    loading.classList.add('flex');
});

document.querySelectorAll('[data-processing-form]').forEach((processingForm) => {
    processingForm.addEventListener('submit', () => {
        loading.classList.remove('hidden');
        loading.classList.add('flex');
    });
});

if (sqlSelect?.value) inspectSql(sqlSelect.value);

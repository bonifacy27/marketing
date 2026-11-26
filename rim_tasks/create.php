<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;

$listId = 322;
$templateId = 865;
$formPath = '/forms/marketing/rim_tasks/create.php';

\Bitrix\Main\Context::getCurrent()->getResponse()->addHeader('Cache-Control', 'no-store');

$textFields = [
    'TEMA' => 'Тема',
    'NOMER_PROEKTA' => 'Номер проекта',
    'DATA_GOTOVNOSTI_MATERIALOV_RK' => 'Дата готовности материалов / РК',
    'DATA_ZAPUSKA_AKTIVNOSTI' => 'Дата запуска активности',
    'DATA_ZAVERSHENIYA_AKTIVNOSTI' => 'Дата завершения активности',
    'PREVIEW_TEXT' => 'Описание',
];
$properties = [
    'TSENTRY_KOMPETENTSIY' => [1969, 'L'],
    'BRIF' => [1970, 'F'],
    'INYE_DOKUMENTY' => [1971, 'F'],
    'TEMA' => [1974, 'S'],
    'NOMER_PROEKTA' => [2114, 'S'],
    'DATA_GOTOVNOSTI_MATERIALOV_RK' => [1981, 'S'],
    'DATA_ZAPUSKA_AKTIVNOSTI' => [1982, 'S'],
    'DATA_ZAVERSHENIYA_AKTIVNOSTI' => [1983, 'S'],
];

$h = static function ($value) {
    return htmlspecialcharsbx((string)$value);
};
$post = static function ($key) {
    return isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : '';
};
$values = [];
foreach ($textFields as $code => $label) {
    $values[$code] = $post($code);
}
$briefAnswer = $post('brief_answer');
$selected = isset($_POST['TSENTRY_KOMPETENTSIY']) && is_array($_POST['TSENTRY_KOMPETENTSIY'])
    ? array_unique(array_filter($_POST['TSENTRY_KOMPETENTSIY'], 'is_string')) : [];
$centres = [];
$required = [];
$multiple = [];
$errors = [];
$available = false;

if ($USER->IsAuthorized() && Loader::includeModule('lists') && Loader::includeModule('iblock')
    && Loader::includeModule('bizproc')) {
    $listType = CIBlock::GetArrayByID($listId, 'IBLOCK_TYPE_ID');
    if ($listType) {
        $documentType = BizprocDocument::generateDocumentComplexType($listType, $listId);
        $available = CBPDocument::CanUserOperateDocumentType(
            CBPCanUserOperateOperation::WriteDocument,
            $USER->GetID(),
            $documentType,
            ['sectionId' => 0]
        );
    }
    if ($available) {
        foreach ($properties as $code => [$id, $type]) {
            $property = CIBlockProperty::GetByID($id, $listId)->Fetch();
            if (!$property || (int)$property['IBLOCK_ID'] !== $listId || $property['CODE'] !== $code
                || $property['PROPERTY_TYPE'] !== $type || $property['ACTIVE'] !== 'Y') {
                $available = false;
                break;
            }
            $required[$code] = $property['IS_REQUIRED'] === 'Y';
            $multiple[$code] = $property['MULTIPLE'] === 'Y';
        }
    }
    if ($available && $multiple['TSENTRY_KOMPETENTSIY']) {
        $options = CIBlockPropertyEnum::GetList(['SORT' => 'ASC', 'VALUE' => 'ASC'], ['PROPERTY_ID' => 1969]);
        while ($option = $options->Fetch()) {
            $centres[$option['ID']] = $option['VALUE'];
        }
    }
    $available = $available && $centres;
    if ($available) {
        $template = CBPWorkflowTemplateLoader::GetList(
            [], ['ID' => $templateId, 'DOCUMENT_TYPE' => $documentType], false, false, ['ID']
        )->Fetch();
        $available = (bool)$template;
    }
}

if (!$available) {
    $errors[] = $USER->IsAuthorized()
        ? 'Форма недоступна. Проверьте права на создание заявок и настройки списка.'
        : 'Войдите на портал, чтобы создать заявку.';
}

$drafts = &$_SESSION['rim_request_forms'];
if (!is_array($drafts)) {
    $drafts = [];
}
$userId = (int)$USER->GetID();
foreach ($drafts as $key => $draft) {
    if ($draft['user'] !== $userId || $draft['created'] < time() - 86400) {
        unset($drafts[$key]);
    }
}

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$token = $isPost ? $post('request_token') : '';
$result = null;
if ($isPost && $available) {
    if (!check_bitrix_sessid() || !isset($drafts[$token])) {
        $errors[] = 'Срок действия формы истёк. Заполните недостающие поля и отправьте её ещё раз.';
        $token = '';
    } elseif (isset($drafts[$token]['result'])) {
        $result = $drafts[$token]['result'];
    } else {
        if (!in_array($briefAnswer, ['yes', 'no'], true)) {
            $errors[] = 'Укажите, есть ли у вас готовый бриф.';
        }
        if (array_diff($selected, array_keys($centres)) || (!$selected && $required['TSENTRY_KOMPETENTSIY'])) {
            $errors[] = 'Выберите центры компетенций из списка.';
        }

        $propertyValues = [1969 => array_values($selected)];
        foreach ($textFields as $code => $label) {
            $value = $values[$code];
            if ($value === '' && ($code === 'TEMA' || !empty($required[$code]))) {
                $errors[] = 'Заполните поле «' . $label . '».';
            }
            if ($value !== '' && strpos($code, 'DATA_') === 0) {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                if (!$date || $date->format('Y-m-d') !== $value) {
                    $errors[] = 'Укажите корректную дату в поле «' . $label . '».';
                } else {
                    $value = $date->format('d.m.Y');
                }
            }
            if (isset($properties[$code])) {
                $propertyValues[$properties[$code][0]] = $value;
            }
        }

        foreach (['BRIF' => 'Бриф', 'INYE_DOKUMENTY' => 'Иные документы'] as $code => $label) {
            $upload = $_FILES[$code] ?? null;
            $files = [];
            if ($upload && is_array($upload['error'])) {
                foreach ($upload['error'] as $i => $error) {
                    if ($error === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }
                    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'][$i])) {
                        $errors[] = 'Не удалось загрузить файл в поле «' . $label . '».';
                        continue;
                    }
                    $files[] = [
                        'name' => $upload['name'][$i],
                        'type' => $upload['type'][$i],
                        'tmp_name' => $upload['tmp_name'][$i],
                        'size' => $upload['size'][$i],
                        'error' => $error,
                    ];
                }
            }
            if (!$files && ($code === 'BRIF' || $required[$code])) {
                $errors[] = 'Прикрепите файл в поле «' . $label . '».';
            }
            if (count($files) > 1 && !$multiple[$code]) {
                $errors[] = 'Для поля «' . $label . '» можно прикрепить только один файл.';
            }
            foreach ($files as $i => $file) {
                $propertyValues[$properties[$code][0]]['n' . $i] = ['VALUE' => $file];
            }
        }

        if (!$errors) {
            $element = new CIBlockElement();
            $id = false;
            try {
                $id = $element->Add([
                    'IBLOCK_ID' => $listId,
                    'NAME' => $values['TEMA'],
                    'ACTIVE' => 'Y',
                    'CREATED_BY' => $USER->GetID(),
                    'MODIFIED_BY' => $USER->GetID(),
                    'PREVIEW_TEXT' => $values['PREVIEW_TEXT'],
                    'PREVIEW_TEXT_TYPE' => 'text',
                    'PROPERTY_VALUES' => $propertyValues,
                ], false, true, true);
            } catch (Throwable $exception) {
                AddMessage2Log($exception->getMessage(), 'rim_tasks');
                $errors[] = 'Не удалось подтвердить сохранение заявки. Проверьте раздел «Мои заявки» перед повторной отправкой.';
            }
            if (!$id) {
                if (!$errors) {
                    $details = trim(strip_tags(str_replace(['<br>', '<br />'], ' ', (string)$element->LAST_ERROR)));
                    $errors[] = 'Не удалось создать заявку. ' . ($details ?: 'Битрикс не вернул причину ошибки.');
                }
            } else {
                // Record the saved item before launching the workflow, so replaying the form cannot create another item.
                $result = ['id' => (int)$id, 'started' => false];
                $drafts[$token]['result'] = $result;
                $workflowErrors = [];
                try {
                    $workflow = CBPDocument::StartWorkflow(
                        $templateId,
                        BizprocDocument::getDocumentComplexId($listType, $id),
                        [CBPDocument::PARAM_TAGRET_USER => 'user_' . $USER->GetID()],
                        $workflowErrors
                    );
                    $result['started'] = (bool)$workflow;
                    if (!$workflow) {
                        AddMessage2Log(['element' => $id, 'errors' => $workflowErrors], 'rim_tasks');
                        $result['diagnostics'] = [];
                        foreach (array_slice($workflowErrors, 0, 3) as $error) {
                            $message = trim(strip_tags((string)($error['message'] ?? '')));
                            if ($message !== '') {
                                $result['diagnostics'][] = $message;
                            }
                        }
                        if (!$result['diagnostics']) {
                            $result['diagnostics'][] = 'Битрикс не вернул причину ошибки запуска.';
                        }
                    }
                } catch (Throwable $exception) {
                    AddMessage2Log(['element' => $id, 'error' => $exception->getMessage()], 'rim_tasks');
                    $result['diagnostics'] = ['При запуске произошла внутренняя ошибка. Подробности записаны в журнал.'];
                }
                $drafts[$token]['result'] = $result;
            }
        }
    }
}

if (!$isPost && isset($_GET['submitted']) && is_string($_GET['submitted'])) {
    $result = $drafts[$_GET['submitted']]['result'] ?? null;
}
if ($result && $isPost) {
    LocalRedirect($formPath . '?submitted=' . $token, false, '303 See Other');
}

if ($available && !$result && (!$token || !$isPost)) {
    $token = bin2hex(random_bytes(24));
    $drafts[$token] = ['user' => $userId, 'created' => time()];
}

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php';
$APPLICATION->SetTitle('Заявка на изготовление рекламных материалов');
?>
<style>
.rim {max-width: 840px; margin: 28px auto; padding: 0 16px; color: #203042}
.rim a {color: #1754a6}
.rim-card {margin: 16px 0; padding: 20px; border: 1px solid #d9e2ed; border-radius: 12px; background: #fff}
.rim legend {padding: 0 8px; font-weight: 700}
.rim [hidden] {display: none}
.rim-fields {min-width: 0; margin: 0; padding: 0; border: 0}
.rim-stack {display: grid; gap: 16px; align-content: start}
.rim-dates {margin-top: 16px; max-width: 320px}
@media (max-width: 600px) {
    .rim-dates {max-width: none}
}
.rim-grid {display: grid; grid-template-columns: repeat(auto-fit,minmax(min(100%,220px),1fr)); gap: 16px}
.rim label span {display: block; margin-bottom: 6px; font-weight: 600}
.rim input:not([type=checkbox]):not([type=radio]), .rim textarea {display: block; box-sizing: border-box; width: 100%; font: inherit; padding: 9px; border: 1px solid #a8b6c6; border-radius: 6px}
.rim textarea {resize: vertical}
.rim-file-list {margin: 6px 0 0; padding-inline-start: 20px; overflow-wrap: anywhere}
.rim-file-list:empty {margin: 0}
.rim-options {display: flex; flex-wrap: wrap; gap: 10px 20px}
.rim-options label {display: inline-flex; gap: 6px; align-items: baseline}
.rim-actions {display: flex; gap: 16px; flex-wrap: wrap; align-items: center}
.rim button, .rim a.rim-primary {padding: 11px 17px; background: #1754a6; color: #fff; border: 0; border-radius: 6px; cursor: pointer; font: inherit; font-weight: 700}
.rim a.rim-primary {display: inline-block; text-decoration: none}
.rim a.rim-primary:hover {background: #103e7c}
.rim-brief-help {margin-top: 16px; padding: 16px; border: 1px solid #90afe0; border-left: 4px solid #1754a6; border-radius: 8px; background: #eef5ff}
.rim-brief-help h2 {margin: 0; font-size: 1.15rem}
.rim-brief-help ol {margin: 12px 0 16px; padding-left: 24px}
.rim-brief-help li + li {margin-top: 8px}
.rim button.rim-secondary {border: 1px solid #1754a6; background: #fff; color: #1754a6}
.rim-error {background: #fff2f1; border-color: #f3b4ae}
</style>
<div class="rim">
    <?php if ($errors): ?>
        <div class="rim-card rim-error" role="alert">
            <ul><?php foreach ($errors as $error): ?><li><?= $h($error) ?></li><?php endforeach; ?></ul>
            <?php if ($isPost && $available): ?><p>Перед повторной отправкой прикрепите файлы заново.</p><?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($result && $result['started']): ?>
        <div class="rim-card">
            <p role="status">Заявка №<?= $h($result['id']) ?> успешно создана. Бизнес-процесс запущен.</p>
            <a class="rim-primary" href="/my_requests/my_requests.php">Вернуться</a>
        </div>
    <?php elseif ($result): ?>
        <div class="rim-card rim-error" role="alert">
            <p>Заявка №<?= $h($result['id']) ?> создана, но запуск бизнес-процесса не подтверждён.</p>
            <ul>
                <?php foreach ($result['diagnostics'] ?? ['Проверьте состояние процесса в созданной заявке.'] as $message): ?>
                    <li><?= $h($message) ?></li>
                <?php endforeach; ?>
            </ul>
            <p><a href="/bizproc/processes/322/element/0/<?= $h($result['id']) ?>/">Открыть заявку</a></p>
            <a href="<?= $h($formPath) ?>">Создать ещё заявку</a>
        </div>
    <?php elseif ($available): ?>
        <form method="post" action="<?= $h($formPath) ?>" enctype="multipart/form-data">
            <?= bitrix_sessid_post() ?>
            <input type="hidden" name="request_token" value="<?= $h($token) ?>">
            <fieldset class="rim-card">
                <legend>У вас есть готовый файл с брифом?</legend>
                <div class="rim-options">
                    <label><input type="radio" name="brief_answer" value="yes" required <?= $briefAnswer === 'yes' ? 'checked' : '' ?>> Да</label>
                    <label><input type="radio" name="brief_answer" value="no" <?= $briefAnswer === 'no' ? 'checked' : '' ?>> Нет</label>
                </div>
                <section class="rim-brief-help" id="rim-brief-help" aria-labelledby="rim-brief-title" <?= $briefAnswer === 'no' ? '' : 'hidden' ?>>
                    <h2 id="rim-brief-title">Создайте бриф, чтобы продолжить</h2>
                    <ol>
                        <li><strong>Откройте мастер заполнения брифа.</strong> Он откроется в новой вкладке, а заявка останется в этой.</li>
                        <li><strong>Заполните бриф по шагам.</strong> В конце мастер сформирует файл XLSX. Скачайте его.</li>
                        <li><strong>Вернитесь в эту вкладку.</strong> Нажмите «Бриф готов, продолжить» и прикрепите скачанный файл в поле «Бриф».</li>
                    </ol>
                    <div class="rim-actions">
                        <a class="rim-primary" href="/forms/marketing/brief_gen.html" target="_blank" rel="noopener">Открыть мастер в новой вкладке</a>
                        <button type="button" class="rim-secondary" id="rim-brief-ready">Бриф готов, продолжить</button>
                    </div>
                </section>
                <noscript><p>Включите JavaScript, чтобы перейти к заполнению заявки.</p></noscript>
            </fieldset>

            <fieldset class="rim-fields" id="rim-request-fields" <?= $briefAnswer === 'yes' ? '' : 'hidden disabled' ?>>
                <fieldset class="rim-card">
                    <legend>Бриф и документы</legend>
                    <div class="rim-grid">
                        <div>
                            <label><span>Бриф *</span><input id="rim-brief-file" type="file" name="BRIF[]" aria-describedby="rim-brief-files" required <?= $multiple['BRIF'] ? 'multiple' : '' ?>></label>
                            <ul class="rim-file-list" id="rim-brief-files" aria-live="polite"></ul>
                        </div>
                        <div>
                            <label><span>Иные документы<?= $required['INYE_DOKUMENTY'] ? ' *' : '' ?></span><input type="file" name="INYE_DOKUMENTY[]" aria-describedby="rim-other-files" <?= $required['INYE_DOKUMENTY'] ? 'required' : '' ?> <?= $multiple['INYE_DOKUMENTY'] ? 'multiple' : '' ?>></label>
                            <ul class="rim-file-list" id="rim-other-files" aria-live="polite"></ul>
                        </div>
                    </div>
                </fieldset>

                <fieldset class="rim-card">
                    <legend>Центры компетенций<?= $required['TSENTRY_KOMPETENTSIY'] ? ' *' : '' ?></legend>
                    <div class="rim-options">
                        <?php foreach ($centres as $key => $name): ?>
                            <label><input type="checkbox" name="TSENTRY_KOMPETENTSIY[]" value="<?= $h($key) ?>" <?= in_array((string)$key, $selected, true) ? 'checked' : '' ?>> <?= $h($name) ?></label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>

                <fieldset class="rim-card">
                    <legend>Данные заявки</legend>
                    <div class="rim-stack">
                        <label>
                            <span>Тема *</span>
                            <input type="text" name="TEMA" value="<?= $h($values['TEMA']) ?>" required>
                        </label>
                        <label>
                            <span>Описание</span>
                            <textarea name="PREVIEW_TEXT" rows="5"><?= $h($values['PREVIEW_TEXT']) ?></textarea>
                        </label>
                        <label>
                            <span>Номер проекта<?= $required['NOMER_PROEKTA'] ? ' *' : '' ?></span>
                            <input type="text" name="NOMER_PROEKTA" value="<?= $h($values['NOMER_PROEKTA']) ?>" <?= $required['NOMER_PROEKTA'] ? 'required' : '' ?>>
                        </label>
                    </div>
                    <div class="rim-stack rim-dates">
                        <?php foreach (['DATA_GOTOVNOSTI_MATERIALOV_RK', 'DATA_ZAPUSKA_AKTIVNOSTI', 'DATA_ZAVERSHENIYA_AKTIVNOSTI'] as $code): ?>
                            <label>
                                <span><?= $h($textFields[$code]) ?><?= $required[$code] ? ' *' : '' ?></span>
                                <input type="date" name="<?= $h($code) ?>" value="<?= $h($values[$code]) ?>" <?= $required[$code] ? 'required' : '' ?>>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
                <div class="rim-actions">
                    <button type="submit">Создать заявку и запустить процесс</button>
                    <span>* Обязательные поля</span>
                </div>
            </fieldset>
        </form>
        <script>
        (() => {
            const fields = document.getElementById('rim-request-fields');
            const help = document.getElementById('rim-brief-help');
            const yes = fields.form.elements.brief_answer[0];
            const no = fields.form.elements.brief_answer[1];
            const update = () => {
                fields.hidden = !yes.checked;
                fields.disabled = !yes.checked;
                help.hidden = !no.checked;
            };
            yes.addEventListener('change', update);
            no.addEventListener('change', update);
            document.getElementById('rim-brief-ready').addEventListener('click', () => {
                yes.checked = true;
                update();
                document.getElementById('rim-brief-file').focus();
            });
            const sizeFormat = new Intl.NumberFormat('ru-RU', {maximumFractionDigits: 1});
            const formatSize = bytes => {
                if (bytes >= 1024 ** 3) return sizeFormat.format(bytes / 1024 ** 3) + ' ГБ';
                if (bytes >= 1024 ** 2) return sizeFormat.format(bytes / 1024 ** 2) + ' МБ';
                if (bytes >= 1024) return sizeFormat.format(bytes / 1024) + ' КБ';
                return sizeFormat.format(bytes) + ' Б';
            };

            fields.querySelectorAll('input[type="file"]').forEach(input => {
                const list = document.getElementById(input.getAttribute('aria-describedby'));
                const updateFiles = () => {
                    list.textContent = '';
                    for (const file of input.files) {
                        const item = document.createElement('li');
                        item.textContent = file.name + ' — ' + formatSize(file.size);
                        list.appendChild(item);
                    }
                };
                input.addEventListener('change', updateFiles);
                window.addEventListener('pageshow', updateFiles);
                updateFiles();
            });
            window.addEventListener('pageshow', update);
            update();
        })();
        </script>
    <?php endif; ?>
</div>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'; ?>

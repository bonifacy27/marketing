<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;

$listId = 322;
$templateId = 865;
$listUrl = '/bizproc/processes/322/view/0/?list_section_id=';

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
            if (!$id) {
                $errors[] = 'Не удалось создать заявку. ' . $element->LAST_ERROR;
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
                    }
                } catch (Throwable $exception) {
                    AddMessage2Log($exception->getMessage(), 'rim_tasks');
                }
                $drafts[$token]['result'] = $result;
            }
        }
    }
}

if ($result && $isPost) {
    LocalRedirect('/rim_tasks/create.php?submitted=' . $token, false, '303 See Other');
}
if (!$isPost && isset($_GET['submitted']) && is_string($_GET['submitted'])) {
    $result = $drafts[$_GET['submitted']]['result'] ?? null;
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
.rim-details {display: grid; grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr); gap: 16px}
.rim-stack {display: grid; gap: 16px; align-content: start}
.rim-dates {margin-top: 16px; max-width: 320px}
@media (max-width: 600px) {
    .rim-details {grid-template-columns: 1fr}
    .rim-dates {max-width: none}
}
.rim-grid {display: grid; grid-template-columns: repeat(auto-fit,minmax(min(100%,220px),1fr)); gap: 16px}
.rim label span {display: block; margin-bottom: 6px; font-weight: 600}
.rim input:not([type=checkbox]):not([type=radio]), .rim textarea {display: block; box-sizing: border-box; width: 100%; font: inherit; padding: 9px; border: 1px solid #a8b6c6; border-radius: 6px}
.rim textarea {resize: vertical}
.rim-options {display: flex; flex-wrap: wrap; gap: 10px 20px}
.rim-options label {display: inline-flex; gap: 6px; align-items: baseline}
.rim-actions {display: flex; gap: 16px; flex-wrap: wrap; align-items: center}
.rim button {padding: 11px 17px; background: #1754a6; color: #fff; border: 0; border-radius: 6px; cursor: pointer; font: inherit; font-weight: 700}
.rim-error {background: #fff2f1; border-color: #f3b4ae}
</style>
<div class="rim">
    <a href="<?= $h($listUrl) ?>">К списку заявок</a>
    <?php if ($errors): ?>
        <div class="rim-card rim-error" role="alert">
            <ul><?php foreach ($errors as $error): ?><li><?= $h($error) ?></li><?php endforeach; ?></ul>
            <?php if ($isPost && $available): ?><p>Перед повторной отправкой прикрепите файлы заново.</p><?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($result): ?>
        <div class="rim-card" role="status">
            <p>Заявка №<?= $h($result['id']) ?> создана.</p>
            <p><?= $result['started']
                ? 'Бизнес-процесс запущен.'
                : 'Не удалось подтвердить запуск бизнес-процесса. Проверьте его состояние в созданной заявке.' ?></p>
            <p><a href="/bizproc/processes/322/element/0/<?= $h($result['id']) ?>/">Открыть заявку</a></p>
            <a href="/rim_tasks/create.php">Создать ещё заявку</a>
        </div>
    <?php elseif ($available): ?>
        <form method="post" action="/rim_tasks/create.php" enctype="multipart/form-data">
            <?= bitrix_sessid_post() ?>
            <input type="hidden" name="request_token" value="<?= $h($token) ?>">
            <fieldset class="rim-card">
                <legend>У вас есть готовый файл с брифом?</legend>
                <div class="rim-options">
                    <label><input type="radio" name="brief_answer" value="yes" required <?= $briefAnswer === 'yes' ? 'checked' : '' ?>> Да</label>
                    <label><input type="radio" name="brief_answer" value="no" <?= $briefAnswer === 'no' ? 'checked' : '' ?>> Нет</label>
                </div>
                <div id="rim-brief-help" <?= $briefAnswer === 'no' ? '' : 'hidden' ?>>
                    <p><a href="/forms/marketing/brief_gen.html" target="_blank" rel="noopener">Создать бриф в новой вкладке</a>. Скачайте XLSX и вернитесь, чтобы прикрепить его к заявке.</p>
                    <button type="button" id="rim-brief-ready">Бриф готов, продолжить</button>
                </div>
                <noscript><p>Включите JavaScript, чтобы перейти к заполнению заявки.</p></noscript>
            </fieldset>

            <fieldset class="rim-fields" id="rim-request-fields" <?= $briefAnswer === 'yes' ? '' : 'hidden disabled' ?>>
                <fieldset class="rim-card">
                    <legend>Бриф и документы</legend>
                    <div class="rim-grid">
                        <label><span>Бриф *</span><input id="rim-brief-file" type="file" name="BRIF[]" required <?= $multiple['BRIF'] ? 'multiple' : '' ?>></label>
                        <label><span>Иные документы<?= $required['INYE_DOKUMENTY'] ? ' *' : '' ?></span><input type="file" name="INYE_DOKUMENTY[]" <?= $required['INYE_DOKUMENTY'] ? 'required' : '' ?> <?= $multiple['INYE_DOKUMENTY'] ? 'multiple' : '' ?>></label>
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
                    <div class="rim-details">
                        <label>
                            <span>Тема *</span>
                            <input type="text" name="TEMA" value="<?= $h($values['TEMA']) ?>" required>
                        </label>
                        <div class="rim-stack">
                            <label>
                                <span>Описание</span>
                                <textarea name="PREVIEW_TEXT" rows="5"><?= $h($values['PREVIEW_TEXT']) ?></textarea>
                            </label>
                            <label>
                                <span>Номер проекта<?= $required['NOMER_PROEKTA'] ? ' *' : '' ?></span>
                                <input type="text" name="NOMER_PROEKTA" value="<?= $h($values['NOMER_PROEKTA']) ?>" <?= $required['NOMER_PROEKTA'] ? 'required' : '' ?>>
                            </label>
                        </div>
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
            window.addEventListener('pageshow', update);
            update();
        })();
        </script>
    <?php endif; ?>
</div>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'; ?>

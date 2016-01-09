<?php
namespace ide\forms;

use Dialog;
use ide\action\AbstractActionType;
use ide\action\AbstractSimpleActionType;
use ide\action\Action;
use ide\action\ActionEditor;
use ide\action\ActionScript;
use ide\editors\AbstractEditor;
use ide\editors\CodeEditor;
use ide\editors\common\CodeTextArea;
use ide\editors\FormEditor;
use ide\editors\menu\ContextMenu;
use ide\forms\mixins\DialogFormMixin;
use ide\Ide;
use ide\misc\AbstractCommand;
use ide\utils\PhpParser;
use php\format\ProcessorException;
use php\gui\event\UXDragEvent;
use php\gui\event\UXEvent;
use php\gui\event\UXMouseEvent;
use php\gui\framework\AbstractForm;
use php\gui\framework\Timer;
use php\gui\layout\UXAnchorPane;
use php\gui\layout\UXFlowPane;
use php\gui\layout\UXHBox;
use php\gui\layout\UXScrollPane;
use php\gui\layout\UXVBox;
use php\gui\paint\UXColor;
use php\gui\UXApplication;
use php\gui\UXButton;
use php\gui\UXCheckbox;
use php\gui\UXClipboard;
use php\gui\UXContextMenu;
use php\gui\UXDialog;
use php\gui\UXForm;
use php\gui\UXLabel;
use php\gui\UXListCell;
use php\gui\UXListView;
use php\gui\UXSplitPane;
use php\gui\UXTab;
use php\gui\UXTabPane;
use php\io\MemoryStream;
use php\lang\IllegalStateException;
use php\lib\Items;
use php\lib\Str;
use php\util\Flow;
use php\xml\XmlProcessor;
use script\TimerScript;

/**
 * Class ActionConstructorForm
 * @package ide\forms
 *
 * @property UXListView $list
 * @property UXTabPane $actionTypePane
 * @property UXAnchorPane $generatedCodeContent
 * @property UXAnchorPane $codeContent
 * @property UXTabPane $tabs
 * @property UXCheckbox $useDefaultCheckbox
 * @property UXSplitPane $constructorSplitPane
 */
class ActionConstructorForm extends AbstractIdeForm
{
    use DialogFormMixin;

    /**
     * @var ActionEditor
     */
    protected $editor;

    /**
     * @var FormEditor
     */
    protected $contextEditor;

    /**
     * @var CodeEditor
     */
    protected $liveCodeEditor;

    /** @var string */
    protected $class;

    /** @var string */
    protected $method;

    /**
     * @var array
     */
    protected $context;

    /**
     * @var TimerScript
     */
    protected $timer;

    protected static $tabSelectedIndex = -1;
    protected static $globalTabSelectedIndex = 0;

    /**
     * @return array
     */
    public function getContext()
    {
        return $this->context;
    }

    /**
     * @param array $context
     */
    public function setContext($context)
    {
        $this->context = $context;
    }

    public function setDefaultEventEditor($editor)
    {
        Ide::get()->setUserConfigValue(CodeEditor::class . '.editorOnDoubleClick', $editor);
    }

    protected function init()
    {
        parent::init();

        $tabOne = $this->tabs->tabs[0]->text;
        $tabTwo = $this->tabs->tabs[1]->text;

        $this->timer = new TimerScript(1000, true, function () use ($tabOne, $tabTwo) {
            $this->tabs->tabs[0]->text = "$tabOne (" . $this->list->items->count . ")";
            $this->tabs->tabs[1]->text = "$tabTwo (" . sizeof(str::split($this->getLiveCode(), "\n")) . ")";
        });

        UXApplication::runLater(function () {
            $this->useDefaultCheckbox->observer('selected')->addListener(function ($oldValue, $newValue) {
                if ($newValue) {
                    $this->setDefaultEventEditor('constructor');
                } else {
                    $this->setDefaultEventEditor('php');
                }
            });
        });

        $this->liveCodeEditor = $liveCodeEditor = new CodeEditor(null, 'php');
        $liveCodeEditor->registerDefaultCommands();

        $liveCodeView = $liveCodeEditor->makeUi();

        UXAnchorPane::setAnchor($liveCodeView, 2);
        $this->codeContent->add($liveCodeView);

        // -----

        $this->list->multipleSelection = true;

        $this->list->on('dragOver', [$this, 'listDragOver']);
        $this->list->on('dragDone', [$this, 'listDragDone']);
        $this->list->on('dragDrop', [$this, 'listDragDrop']);

        $this->list->setDraggableCellFactory([$this, 'listCellFactory'], [$this, 'listCellDragDone']);
        $this->hintLabel->mouseTransparent = true;

        $contextMenu = new ContextMenu();

        $contextMenu->addCommand(AbstractCommand::make('Редактировать', 'icons/edit16.png', function () {
            $this->actionEdit();
        }));

        $contextMenu->addSeparator();

        $contextMenu->addCommand(AbstractCommand::make('Вставить', 'icons/paste16.png', function () {
            $this->actionPaste();
        }, 'Ctrl+V'));

        $contextMenu->addCommand(AbstractCommand::make('Вырезать', 'icons/cut16.png', function () {
            $this->actionCopy();
            $this->actionDelete();
        }, 'Ctrl+X'));

        $contextMenu->addCommand(AbstractCommand::make('Копировать', 'icons/copy16.png', function () {
            $this->actionCopy();
        }, 'Ctrl+C'));

        $contextMenu->addCommand(AbstractCommand::make('Удалить', 'icons/delete16.png', function () {
            $this->actionDelete();
        }, 'Delete'));

        $this->list->contextMenu = $contextMenu->getRoot();
    }

    protected function listDragOver(UXDragEvent $e)
    {
        $e->acceptTransferModes(['MOVE']);
        $e->consume();
    }

    protected function listDragDone(UXDragEvent $e)
    {
        $e->consume();
    }

    protected function listDragDrop(UXDragEvent $e)
    {
        if ($this->list->items->count > 0) {
            return;
        }

        $dragboard = $e->dragboard;

        $value = $dragboard->string;

        if (class_exists($value)) {
            $actionType = new $value();

            if ($actionType instanceof AbstractSimpleActionType) {
                $dragboard->dragView = null;

                /** @var ActionConstructorForm $self */
                $self = $e->sender->scene->window->userData->self;

                UXApplication::runLater(function () use ($self, $actionType) {
                    $self->addAction($actionType);
                });

                $e->dropCompleted = true;
                $e->consume();
            }
        }
    }

    protected function listCellDragDone(UXDragEvent $e, UXListView $list, $index)
    {
        $dragboard = $e->dragboard;

        $value = $dragboard->string;

        if (class_exists($value)) {
            $actionType = new $value();
            $dragboard->dragView = null;

            if ($actionType instanceof AbstractSimpleActionType) {
                /** @var ActionConstructorForm $self */
                $self = $list->scene->window->userData->self;

                UXApplication::runLater(function () use ($self, $actionType, $index) {
                    $self->addAction($actionType, $index);
                    $this->editor->updateMethod($this->class, $this->method, Items::toArray($this->list->items));
                });
            } else {
                return false;
            }
        } elseif (Str::isNumber($value) && $value < $list->items->count && $value >= 0) {
            $indexes = $list->selectedIndexes;

            $dragged = [];

            foreach ($indexes as $i) {
                $dragged[] = $list->items[$i];
            }

            if ($index < (int) ($value)) {
                foreach ($dragged as $el) {
                    $list->items->remove($el);
                }

                foreach ($dragged as $el) {
                    $list->items->insert($index++, $el);
                }

                $index--;
            } else {
                $index++;

                foreach ($dragged as $el) {
                    $index--;
                    $list->items->remove($el);
                }

                foreach ($dragged as $el) {
                    $list->items->insert($index++, $el);
                }

                $index--;
            }

            UXApplication::runLater(function () use ($index) {
                $this->list->selectedIndex = $index;
            });
        }

        $this->editor->updateMethod($this->class, $this->method, Items::toArray($this->list->items));

        $this->updateList();

        $this->editor->calculateLevels(Items::toArray($this->list->items));
        $this->list->update();
    }

    protected function listCellFactory(UXListCell $cell, Action $action = null, $empty)
    {
        if ($action) {
            $titleName = new UXLabel($action->getTitle());
            $titleName->style = '-fx-font-weight: bold; -fx-text-fill: #383838;';

            if ($action->getDescription()) {
                $titleDescription = new UXLabel($action->getDescription());
                $titleDescription->style = '-fx-text-fill: gray;';
                $titleDescription->padding = 0;
            } else {
                $titleDescription = null;
            }

            $title = $action->getType()->makeUi($action, $titleName, $titleDescription);

            if ($title instanceof UXVBox || $title instanceof UXHBox) {
                $title->spacing = 0;

                if ($action->getType()->isDeprecated()) {
                    $title->opacity = 0.6;
                    $titleName->tooltipText = $titleDescription->tooltipText = 'Действие устарело, необходимо его заменить чем-то другим';
                }
            }

            $image = Ide::get()->getImage($action->getIcon());

            if (!$image) {
                $image = Ide::get()->getImage('icons/blocks16.png');
            }

            $line = new UXHBox([$image, $title]);
            $line->spacing = 10;
            $line->padding = 5;

            $line->alignment = 'CENTER_LEFT';

            $line->paddingLeft = 20 * $action->getLevel() + 5;

            if ($action->getType()->isCloseLevel() || $action->getType()->isAppendMultipleLevel()) {
                $line->paddingLeft -= 5;
            }

            $cell->text = null;
            $cell->graphic = $line;
        }
    }

    public function updateList()
    {
       $this->hintLabel->visible = !$this->list->items->count;
    }

    public function setContextEditor(AbstractEditor $editor = null)
    {
        $this->contextEditor = $editor;
    }

    /**
     * @return FormEditor
     */
    public function getContextEditor()
    {
        return $this->contextEditor;
    }

    public function setLiveCode($value)
    {
        $this->liveCodeEditor->setValue("<?\n$value");
    }

    public function getLiveCode()
    {
        $value = $this->liveCodeEditor->getValue();

        if (Str::startsWith($value, "<?\n")) {
            $value = str::sub($value, 3);
        }

        if (Str::startsWith($value, "<?")) {
            $value = str::sub($value, 2);
        }

        return $value;
    }

    public function showAndWait(ActionEditor $editor = null, $class = null, $method = null)
    {
        $this->constructorSplitPane->dividerPositions = Ide::get()->getUserConfigArrayValue(get_class($this) . ".dividerPositions", $this->constructorSplitPane->dividerPositions);
        $this->useDefaultCheckbox->selected = Ide::get()->getUserConfigValue(CodeEditor::class . '.editorOnDoubleClick') == "constructor";

        $this->buildActionTypePane($editor);

        $editor->makeSnapshot();

        $this->editor = $editor;
        $this->editor->load();

        $this->class = $class;
        $this->method= $method;

        $actions = $editor->findMethod($class, $method);

        $this->list->items->clear();
        $this->list->items->addAll($actions);

        $this->updateList();
        $this->userData = new \stdClass(); // hack!
        $this->userData->self = $this;

        $this->tabs->selectedIndex = self::$globalTabSelectedIndex;

        $this->timer->start();
        parent::showAndWait();
    }

    public function buildActionTypePane(ActionEditor $editor)
    {
        static $buildTabs;
        static $subGroups = [];

        if (!$buildTabs) {
            $buildTabs = [];

            $actions = $editor->getManager()->getActionTypes();

            $list = [];

            foreach ($actions as $action) {
                if ($action->isDeprecated()) {
                    continue;
                }

                $contexts = $action->forContexts();

                if ($contexts) {
                    $matches = false;

                    foreach ($contexts as $one) {
                        if ($one['class'] && $this->context['class'] == $one['class']) {
                            $matches = true;
                            break;
                        }
                    }

                    if (!$matches) {
                        continue;
                    }
                }

                $group = $action->getGroup();
                $subGroup = $action->getSubGroup();

                $list[$group][$subGroup][] = $action;
            }

            foreach ($list as $group => $elements) {
                foreach ($elements as $subGroup => $actions) {
                    foreach ($actions as $action) {
                        $this->addActionType($action, $buildTabs, $subGroups);
                    }
                }
            }
        }

        $this->actionTypePane->tabs->clear();

        /** @var UXTab $tab */
        $i = 0;
        foreach ($buildTabs as $tab) {
            $t = new UXTab();
            $t->text = $tab->text;
            $t->closable = false;
            $t->content = new UXScrollPane($tab->content);
            $t->content->fitToWidth = true;

            $t->graphic = $tab->graphic;
            $t->style = $tab->style;

            $this->actionTypePane->tabs->add($t);

            if ($i++ == static::$tabSelectedIndex) {
                UXApplication::runLater(function () use ($t) {
                    $this->actionTypePane->selectTab($t);
                });
            }
        }
    }

    private function addActionType(AbstractActionType $actionType, &$buildTabs, &$subGroups)
    {
        $tab = $buildTabs[$actionType->getGroup()];
        $subGroup = $actionType->getSubGroup();

        if (!$tab) {
            $tab = new UXTab();
            $tab->closable = false;
            $tab->text = $actionType->getGroup();

            $tab->content = new UXFlowPane();
            $tab->content->hgap = 6;
            $tab->content->vgap = 6;

            $tab->content->padding = 11;
            $tab->style = '-fx-cursor: hand;';

            $tab->content->alignment = 'TOP_LEFT';

            $buildTabs[$actionType->getGroup()] = $tab;
        }

        if (!$subGroups[$actionType->getGroup()][$subGroup]) {
            if ($subGroup) {
                $label = new UXLabel($subGroup);
                $label->minWidth = 34 * 3;
                $tab->content->observer('width')->addListener(function ($old, $new) use ($label, $tab) {
                    Ide::get()->setUserConfigValue(get_class($this) . ".dividerPositions", $this->constructorSplitPane->dividerPositions);
                    $label->minWidth = $new - $tab->content->paddingLeft - $tab->content->paddingRight;
                });

                if ($subGroups[$actionType->getGroup()]) {
                    $label->paddingTop = 5;
                }

                $tab->content->add($label);
            }

            $subGroups[$actionType->getGroup()][$subGroup] = 1;
        }

        $btn = new UXButton();
        $btn->tooltipText = $actionType->getTitle() . " \n -> " . $actionType->getDescription();
        $btn->graphic = Ide::get()->getImage($actionType->getIcon());
        $btn->size = [34, 34];
        $btn->userData = $actionType;
        $btn->style = '-fx-background-color: white; -fx-border-color: silver; -fx-border-width: 1px; -fx-border-radius: 3px;';

        if ($btn->graphic == null) {
            $btn->graphic = ico('blocks16');
        }

        $btn->on('action', [$this, 'actionTypeClick']);

        $btn->on('dragDetect', function (UXMouseEvent $e) {
            $dragboard = $e->sender->startDrag(['MOVE']);
            $dragboard->dragView = $e->sender->snapshot();

            $dragboard->dragViewOffsetX = $dragboard->dragView->width / 2;
            $dragboard->dragViewOffsetY = $dragboard->dragView->height / 2;

            $dragboard->string = get_class($e->sender->userData);

            $e->consume();
        });

        $tab->content->add($btn);
    }


    /**
     * @param UXEvent|null $e
     */
    protected function actionDelete(UXEvent $e = null)
    {
        /** @var ActionConstructorForm $self */
        $self = $e ? $e->sender->scene->window->userData->self : $this;
        $indexes = $self->list->selectedIndexes;

        if ($indexes) {
            $index = -1;

            $self->editor->removeActions($self->class, $self->method, $indexes);
            $actions = [];

            foreach ($indexes as $index) {
                $actions[] = $self->list->items[$index];
            }

            foreach ($actions as $action) {
                $this->list->items->remove($action);
            }

            $this->editor->updateMethod($self->class, $self->method, Items::toArray($this->list->items));

            $this->list->update();

            $this->updateList();

            $this->list->selectedIndex = $index++;

            if ($index >= $this->list->items->count) {
                $this->list->selectedIndex = $this->list->items->count - 1;
            }
        }
    }

    /**
     * @param UXMouseEvent|null $e
     * @event list.click
     */
    public function actionEdit(UXMouseEvent $e = null)
    {
        if (!$e || $e->clickCount >= 2) {
            /** @var ActionConstructorForm $self */
            $self = $e ? $e->sender->scene->window->userData->self : $this;

            $index = $self->list->selectedIndex;

            if ($index > -1) {
                /** @var Action $action */
                $action = $self->list->items[$index];

                /** @var AbstractSimpleActionType $type */
                $type = $action->getType();

                if ($type->showDialog($action, $self->contextEditor)) {
                    $self->list->update();
                    $self->list->selectedIndex = $index;

                    $this->editor->updateMethod($self->class, $self->method, Items::toArray($this->list->items));
                }
            }
        }
    }

    public function addAction(AbstractSimpleActionType $actionType, $index = -1)
    {
        $self = $this;

        $action = new Action($actionType);

        if ($actionType->showDialog($action, $self->contextEditor, true)) {
            $editor = $self->editor;
            $editor->addAction($action, $self->class, $self->method);

            if ($index == -1 || $index > $self->list->items->count) {
                $self->list->items->add($action);
                $index = $self->list->items->count - 1;
            } else {
                $this->list->items->insert($index, $action);
            }

            $editor->calculateLevels(Items::toArray($self->list->items));
            $self->list->update();

            $self->list->selectedIndex = $index;
            $self->list->focusedIndex = $index;

            $self->list->scrollTo($index);

            $self->updateList();
        }
    }

    protected function actionTypeClick(UXEvent $e)
    {
        /** @var $actionType AbstractSimpleActionType */
        $actionType = $e->sender->userData;

        /** @var ActionConstructorForm $self */
        $self = $e->sender->scene->window->userData->self;

        $self->addAction($actionType);
    }

    /**
     * @event hide
     */
    public function hide()
    {
        parent::hide();

        $this->timer->stop();

        if ($this->editor) {
            $this->editor->cacheData = [];
        }
    }

    /**
     * @event clearButton.action
     */
    public function actionClear()
    {
        if (!$this->list->items->count) {
            Dialog::show('Список из действий пуст.');
            return;
        }

        $dlg = new MessageBoxForm('Вы уверены, что хотите удалить все действия?', ['Да, удалить', 'Нет, отмена']);

        if ($dlg->showDialog() && $dlg->getResultIndex() == 0) {
            $this->editor->removeMethod($this->class, $this->method);

            $this->list->items->clear();
            $this->list->update();
            $this->updateList();
        }
    }

    /**
     * @event previewButton.action
     */
    public function actionPreview()
    {
        if (!$this->list->items->count) {
            Dialog::show('Список из действий пуст.');
            return;
        }

        $script = new ActionScript();

        $imports = $script->getImports(Items::toArray($this->list->items));

        $code = $script->compileActions(
            $this->class,
            $this->method,
            Items::toArray($this->list->items),'','',''
        );

        $phpParser = new PhpParser("<?\n\n" . $code);
        $phpParser->addUseImports($imports);

        $dialog = new UXForm();
        $dialog->title = 'Сгенерированный php код';
        $dialog->style = 'UTILITY';
        $dialog->modality = 'APPLICATION_MODAL';
        $dialog->size = [700, 400];

        $area = new CodeTextArea('php');
        $area->setValue($phpParser->getContent());
        UXVBox::setVgrow($area, 'ALWAYS');

        $okButton = new UXButton('Закрыть');
        $okButton->graphic = ico('ok16');
        $okButton->padding = [10, 15];
        $okButton->maxHeight = 9999;
        $okButton->on('action', function () use ($dialog) { $dialog->hide(); });

        $buttons = new UXHBox([$okButton]);
        $buttons->spacing = 10;
        $buttons->height = 40;

        $pane = new UXVBox([$area, $buttons]);
        $pane->spacing = 10;
        $pane->padding = 10;

        UXAnchorPane::setAnchor($pane, 0);

        $dialog->add($pane);
        $dialog->showAndWait();
    }

    /**
     * @event saveButton.action
     */
    public function actionSave()
    {
        $this->editor->clearSnapshots();
        $this->editor->save();
        static::$tabSelectedIndex = $this->actionTypePane->selectedIndex;
        static::$globalTabSelectedIndex = $this->tabs->selectedIndex;

        $this->setResult(true);
        $this->hide();
    }

    /**
     * @event close
     * @event cancelButton.action
     */
    public function actionCancel()
    {
        $this->setResult(false);

        $this->editor->restoreSnapshot();
        $this->editor->clearSnapshots();
        $this->editor->save();
        static::$tabSelectedIndex = $this->actionTypePane->selectedIndex;
        static::$globalTabSelectedIndex = $this->tabs->selectedIndex;

        $this->hide();
    }

    /**
     * @event convertButton.action
     */
    public function actionConvert()
    {
        if ($this->list->items->count == 0) {
            UXDialog::show('Нет действий для конвертирования в php код');
            return;
        }

        $buttons = ['Да, перевести', 'Нет, отмена'];

        $dialog = new MessageBoxForm('Вы уверены, что хотите перевести все действия в php код?', $buttons);

        if ($dialog->showDialog() && $dialog->getResultIndex() == 0) {
            $script = new ActionScript();

            $imports = $script->getImports(Items::toArray($this->list->items));

            $code = $script->compileActions(
                $this->class,
                $this->method,
                Items::toArray($this->list->items),
                'Сгенерированный код',
                '------------------',
                ''
            );

            $code = $this->getLiveCode() . "\n\n" . $code;

            $phpParser = new PhpParser($code);
            $phpParser->addUseImports($imports);

            $this->setLiveCode($phpParser->getContent());

            $this->editor->removeMethod($this->class, $this->method);
            $this->list->items->clear();

            UXApplication::runLater(function () {
                $this->tabs->selectedIndex = 1;

                UXApplication::runLater(function () {
                    $this->liveCodeEditor->requestFocus();
                });
            });
        }
    }

    public function actionPaste()
    {
        $xmlProcessor = (new XmlProcessor());

        try {
            $xml = $xmlProcessor->parse(UXClipboard::getText());

            $actions = [];
            foreach ($xml->findAll("/actionCopies/*") as $domAction) {
                if ($action = $this->editor->getManager()->buildAction($domAction)) {
                    $actions[] = $action;
                }
            }

            if ($this->list->selectedIndexes) {
                $index = $this->list->selectedIndex;
                $selectedIndexes = Flow::ofRange($index, $index + sizeof($actions))->toArray();

                foreach (Items::reverse($actions) as $action) {
                    $this->list->items->insert($index, $action);
                }
            } else {
                $selectedIndexes = Flow::ofRange($this->list->items->count, $this->list->items->count + sizeof($actions))->toArray();
                $this->list->items->addAll($actions);
            }

            $this->updateList();
            $this->list->update();

            $this->editor->updateMethod($this->class, $this->method, Items::toArray($this->list->items));

            $this->list->selectedIndex = $selectedIndexes;
        } catch (ProcessorException $e) {
            ;
        }
    }

    public function actionCopy()
    {
        $indexes = $this->list->selectedIndexes;

        if ($indexes) {
            $xmlProcessor = (new XmlProcessor());

            $document = $xmlProcessor->createDocument();

            $document->appendChild($root = $document->createElement('actionCopies'));


            foreach ($indexes as $index) {
                /** @var Action $action */
                $action = $this->list->items[$index];

                $element = $document->createElement($action->getType()->getTagName(), [
                    'ideName' => Ide::get()->getName(),
                    'ideVersion' => Ide::get()->getVersion(),
                    'ideNamespace' => Ide::get()->getNamespace(),
                ]);

                $action->getType()->serialize($action, $element, $document);

                $root->appendChild($element);
            }

            UXClipboard::setText($xmlProcessor->format($document));
        }
    }
}
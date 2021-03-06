<?php
namespace verbb\wishlist\controllers;

use verbb\wishlist\Wishlist;
use verbb\wishlist\elements\ListElement;

use Craft;
use craft\base\Element;
use craft\helpers\DateTimeHelper;
use craft\helpers\Json;
use craft\helpers\Localization;
use craft\helpers\UrlHelper;
use craft\models\Site;
use craft\web\Controller;

use craft\commerce\Plugin as Commerce;
use craft\commerce\base\Purchasable;

use yii\base\Exception;
use yii\web\ForbiddenHttpException;
use yii\web\HttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\ServerErrorHttpException;

class ListsController extends Controller
{
    // Properties
    // =========================================================================

    protected $allowAnonymous = ['create', 'delete', 'clear'];
    public static $commercePlugin;


    // Public Methods
    // =========================================================================

    public function init()
    {
        parent::init();

        self::$commercePlugin = Craft::$app->getPlugins()->getPlugin('commerce');
    }

    public function actionIndex(): Response
    {
        // Remove all inactive lists older than a certain date in config.
        Wishlist::getInstance()->getLists()->purgeInactiveLists();

        return $this->renderTemplate('wishlist/lists/index');
    }

    public function actionEditList(string $listTypeHandle, int $listId = null, ListElement $list = null): Response
    {
        $listType = null;

        $variables = [
            'listTypeHandle' => $listTypeHandle,
            'listId' => $listId,
            'list' => $list
        ];

        // Make sure a correct list type handle was passed so we can check permissions
        if ($listTypeHandle) {
            $listType = Wishlist::$plugin->getListTypes()->getListTypeByHandle($listTypeHandle);
        }

        if (!$listType) {
            throw new Exception('The list type was not found.');
        }

        $this->requirePermission('wishlist-manageListType:' . $listType->id);
        $variables['listType'] = $listType;

        $this->_prepareVariableArray($variables);

        if (!empty($variables['list']->id)) {
            $variables['title'] = $variables['list']->title;
        } else {
            $variables['title'] = Craft::t('wishlist', 'Create a new list');
        }

        // Can't just use the entry's getCpEditUrl() because that might include the site handle when we don't want it
        $variables['baseCpEditUrl'] = 'wishlist/lists/' . $variables['listTypeHandle'] . '/{id}';

        // Set the "Continue Editing" URL
        $variables['continueEditingUrl'] = $variables['baseCpEditUrl'];

        return $this->renderTemplate('wishlist/lists/_edit', $variables);
    }

    public function actionDeleteList()
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();
        $session = Craft::$app->getSession();

        $listId = $request->getRequiredParam('listId');
        $list = ListElement::findOne($listId);

        if (!$list) {
            throw new Exception(Craft::t('wishlist', 'No list exists with the ID “{id}”.',['id' => $listId]));
        }

        $this->enforceListPermissions($list);

        if (!Craft::$app->getElements()->deleteElement($list)) {
            if ($request->getAcceptsJson()) {
                $this->asJson(['success' => false]);
            }

            $session->setError(Craft::t('wishlist', 'Couldn’t delete list.'));

            Craft::$app->getUrlManager()->setRouteParams([
                'list' => $list
            ]);

            return null;
        }

        if ($request->getAcceptsJson()) {
            $this->asJson(['success' => true]);
        }

        $session->setNotice(Craft::t('wishlist', 'List deleted.'));

        return $this->redirectToPostedUrl($list);
    }

    public function actionSaveList()
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();

        $list = $this->_setListFromPost();

        $this->enforceListPermissions($list);

        if (!Craft::$app->getElements()->saveElement($list)) {
            if ($request->getAcceptsJson()) {
                return $this->asJson([
                    'success' => false,
                    'errors' => $list->getErrors(),
                ]);
            }

            Craft::$app->getSession()->setError(Craft::t('wishlist', 'Couldn’t save list.'));

            // Send the category back to the template
            Craft::$app->getUrlManager()->setRouteParams([
                'list' => $list
            ]);

            return null;
        }

        if ($request->getAcceptsJson()) {
            return $this->asJson([
                'success' => true,
                'id' => $list->id,
                'title' => $list->title,
                'status' => $list->getStatus(),
                'url' => $list->getUrl(),
                'cpEditUrl' => $list->getCpEditUrl()
            ]);
        }

        Craft::$app->getSession()->setNotice(Craft::t('app', 'List saved.'));

        return $this->redirectToPostedUrl($list);
    }


    // Front-end Methods
    // =========================================================================

    public function actionCreate()
    {
        $request = Craft::$app->getRequest();

        $list = $this->_setListFromPost();
        $list->enabled = true;

        if (!Craft::$app->getElements()->saveElement($list)) {
            if ($request->getAcceptsJson()) {
                return $this->asJson([
                    'success' => false,
                    'errors' => $list->getErrors(),
                ]);
            }

            Craft::$app->getSession()->setError(Craft::t('wishlist', 'Couldn’t save list.'));

            // Send the category back to the template
            Craft::$app->getUrlManager()->setRouteParams([
                'list' => $list
            ]);

            return null;
        }

        if ($request->getAcceptsJson()) {
            return $this->asJson([
                'success' => true,
                'id' => $list->id,
                'title' => $list->title,
                'status' => $list->getStatus(),
                'url' => $list->getUrl(),
                'cpEditUrl' => $list->getCpEditUrl()
            ]);
        }

        Craft::$app->getSession()->setNotice(Craft::t('app', 'List saved.'));

        return $this->redirectToPostedUrl($list);
    }

    public function actionDelete()
    {
        $request = Craft::$app->getRequest();
        $listId = $request->getRequiredParam('listId');

        $list = ListElement::findOne($listId);

        if (!$list) {
            throw new Exception(Craft::t('wishlist', 'No list exists with the ID “{id}”.',['id' => $listId]));
        }

        // Only owners can delete their own lists
        if (!WishList::$plugin->getLists()->isListOwner($list)) {
            throw new Exception(Craft::t('wishlist', 'You can only delete your own list.'));
        }

        if (!Craft::$app->getElements()->deleteElement($list)) {
            if ($request->getAcceptsJson()) {
                $this->asJson(['success' => false]);
            }

            Craft::$app->getUrlManager()->setRouteParams([
                'list' => $list
            ]);

            return null;
        }

        if ($request->getAcceptsJson()) {
            $this->asJson(['success' => true]);
        }

        return $this->redirectToPostedUrl($list);
    }

    public function actionClear()
    {
        $request = Craft::$app->getRequest();
        $listId = $request->getRequiredParam('listId');

        $list = ListElement::findOne($listId);

        if (!$list) {
            throw new Exception(Craft::t('wishlist', 'No list exists with the ID “{id}”.',['id' => $listId]));
        }

        // Only owners can clear their own lists
        if (!WishList::$plugin->getLists()->isListOwner($list)) {
            throw new Exception(Craft::t('wishlist', 'You can only clear your own list.'));
        }

        if (!Wishlist::$plugin->getItems()->deleteItemsForList($listId)) {
            if ($request->getAcceptsJson()) {
                $this->asJson(['success' => false]);
            }

            Craft::$app->getUrlManager()->setRouteParams([
                'list' => $list
            ]);

            return null;
        }

        if ($request->getAcceptsJson()) {
            $this->asJson(['success' => true]);
        }

        return $this->redirectToPostedUrl($list);
    }

    public function actionAddToCart()
    {
        if (!self::$commercePlugin) {
            return;
        }

        $request = Craft::$app->getRequest();
        $listId = $request->getRequiredParam('listId');

        $list = ListElement::findOne($listId);

        if (!$list) {
            throw new Exception(Craft::t('wishlist', 'No list exists with the ID “{id}”.',['id' => $listId]));
        }

        $cart = Commerce::getInstance()->getCarts()->getCart(true);

        foreach ($list->getItems()->indexBy('id')->all() as $key => $item) {
            if (is_a($item->getElement(), Purchasable::class)) {
                $purchasable = $item->getElement();

                $note = $request->getParam("purchasables.{$key}.note", '');
                $options = $request->getParam("purchasables.{$key}.options") ?: [];
                $qty = (int)$request->getParam("purchasables.{$key}.qty", 1);

                // Ignore zero value qty for multi-add forms https://github.com/craftcms/commerce/issues/330#issuecomment-384533139
                if ($qty > 0) {
                    $lineItem = Commerce::getInstance()->getLineItems()->resolveLineItem($cart->id, $purchasable->id, $options);

                    // New line items already have a qty of one.
                    if ($lineItem->id) {
                        $lineItem->qty += $qty;
                    } else {
                        $lineItem->qty = $qty;
                    }

                    $lineItem->note = $note;
                    $cart->addLineItem($lineItem);
                }
            }
        }

        if (!Craft::$app->getElements()->saveElement($cart, false)) {
            if ($request->getAcceptsJson()) {
                $this->asJson(['success' => false]);
            }

            Craft::$app->getUrlManager()->setRouteParams([
                'list' => $list
            ]);

            return null;
        }

        if ($request->getAcceptsJson()) {
            $this->asJson(['success' => true]);
        }

        return $this->redirectToPostedUrl($list);
    }


    // Protected Methods
    // =========================================================================

    protected function enforceListPermissions(ListElement $list)
    {
        if (!$list->getType()) {
            Craft::error('Attempting to access a list that doesn’t have a type', __METHOD__);
            throw new HttpException(404);
        }

        $this->requirePermission('wishlist-manageListType:' . $list->getType()->id);
    }


    // Private Methods
    // =========================================================================

    private function _prepareVariableArray(&$variables)
    {
        // List related checks
        if (empty($variables['list'])) {
            if (!empty($variables['listId'])) {
                $variables['list'] = Craft::$app->getElements()->getElementById($variables['listId'], ListElement::class);

                if (!$variables['list']) {
                    throw new Exception('Missing list data.');
                }
            } else {
                $variables['list'] = new ListElement();
                $variables['list']->typeId = $variables['listType']->id;
            }
        }
    }

    private function _setListFromPost(): ListElement
    {
        $request = Craft::$app->getRequest();
        $listId = $request->getBodyParam('listId');

        if ($listId) {
            $list = Wishlist::getInstance()->getLists()->getListById($listId);

            if (!$list) {
                throw new Exception(Craft::t('wishlist', 'No list with the ID “{id}”', ['id' => $listId]));
            }
        } else {
            $list = Wishlist::$plugin->getLists()->createList();
        }

        $list->typeId = $request->getBodyParam('typeId', $list->typeId);
        $list->enabled = (bool)$request->getBodyParam('enabled', $list->enabled);
        $list->title = $request->getBodyParam('title', $list->title);

        $list->setFieldValuesFromRequest('fields');

        return $list;
    }
}

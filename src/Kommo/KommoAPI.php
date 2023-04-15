<?php
/**
 * Класс KommoAPI. amoCRM REST API wrapper
 *
 * @author    andrey-tech
 * @copyright 2019-2020 andrey-tech
 * @see https://github.com/andrey-tech/amocrm-api-php
 * @license   MIT
 *
 * @version 2.4.0
 *
 * v1.0.0 (24.04.2019) Начальный релиз
 * v1.1.0 (02.06.2019) Добавлены новые параметры, рефракторинг.
 * v1.2.0 (19.08.2019) Добавлен метод deleteObjects()
 * v1.2.1 (19.02.2020) Удален метод deleteObjects()
 * v2.0.0 (06.04.2020) Добавлена авторизация по протоколу OAuth 2.0.
 *                     Добавлены трейты KommoAPIAuth, KommoAPIOAuth2
 * v2.1.0 (10.05.2020) Добавлена проверка ответа сервера в метод saveObjects()
 * v2.2.0 (16.05.2020) Добавлен метод getItems(). Добавлен параметр $returnResponses в метод saveObjects()
 * v2.3.0 (22.05.2020) Добавлен метод deleteObjects() для удаления списков и их элементов
 * v2.3.1 (14.07.2020) Изменен порядок параметров $subdomain и $returnResponse в методах
 * v2.4.0 (09.08.2020) Добавлен метод saveObjectsWithLimit()
 *
 */

declare(strict_types = 1);

namespace Kommo;

class KommoAPI
{
    // Трейт, формирующий GET/POST запросы к amoCRM
    use KommoAPIRequest;

    // Трейт методов для получения информации об аккаунте
    use KommoAPIGetAccount;

    // Трейт методов для получения сущностей
    use KommoAPIGetEntities;

    // Трейт методов для получения всех сущностей
    use KommoAPIGetAllEntities;

    // Трейт методов для авторизации по API-ключам пользователя
    use KommoAPIAuth;

    // Трейт методов для авторизации по протоколу OAuth 2.0
    use KommoAPIOAuth2;

    // Трейт методов для добавления и удаления webhooks
    use KommoAPIWebhooks;

    // Трейт методов для принятия или отклонение неразобранных заявок
    use KommoAPIIncomingLeads;

    /**
     * Возращает массив параметров сущностей из ответа сервера amoCRM
     * @param array|null $response Ответ сервера
     * @return array|null
     */
    public static function getItems($response)
    {
        return $response['_embedded']['items'] ?? null;
    }

    /**
     * Сохраняет (добавляет или обновляет) объекты KommoObject с ограничением на число сущностей в одном запросе к API amoCRM
     * @param array|object $amoObjects Массив объектов KommoObject или объект KommoObject
     * @param bool $returnResponses Возвращать массив ответов сервера amoCRM вместо массива параметров сущностей
     * @param string $subdomain Поддомен amoCRM
     * @param int $limit Максимальное число сущностей в одном запросе к API amoCRM
     * @return array
     * @throws KommoAPIException
     */
    public static function saveObjectsWithLimit(
        $amoObjects,
        bool $returnResponses = false,
        $subdomain = null,
        $limit = 250
    ):array {
        if (! is_array($amoObjects)) {
            $amoObjects = [$amoObjects];
        }

        if (count($amoObjects) < $limit) {
            return self::saveObjects($amoObjects, $returnResponses, $subdomain);
        }

        $responses = [];
        $amoObjectsChunks = array_chunk($amoObjects, $limit);
        foreach ($amoObjectsChunks as $amoObjectsChunk) {
            $responses = array_merge($responses, self::saveObjects($amoObjectsChunk, $returnResponses, $subdomain));
        }

        return $responses;
    }

    /**
     * Сохраняет (добавляет или обновляет) объекты KommoObject
     * @param array|object $amoObjects Массив объектов KommoObject или объект KommoObject
     * @param bool $returnResponses Возвращать массив ответов сервера amoCRM вместо массива параметров сущностей
     * @param string $subdomain Поддомен amoCRM
     * @return array
     * @throws KommoAPIException
     */
    public static function saveObjects($amoObjects, bool $returnResponses = false, $subdomain = null) :array
    {
        if (! is_array($amoObjects)) {
            $amoObjects = [ $amoObjects ];
        }
        
        $parameters = [];
        foreach ($amoObjects as $object) {
            if (isset($object->id)) {
                $parameters[$object::URL]['update'][] = $object->getParams();
            } else {
                $parameters[$object::URL]['add'][] = $object->getParams();
            }
        }

        $responses = [];
        foreach ($parameters as $url => $params) {
            $response = KommoAPI::request($url, 'POST', $params, $subdomain);
            if (empty($response)) {
                throw new KommoAPIException(
                    "Не удалось пакетно добавить/обновить сущности (пустой ответ) по запросу {$url}: " . print_r($params, true)
                );
            }
            $responses[] = $response;
        }

        if (! $returnResponses) {
            $items = [];
            foreach ($responses as $response) {
                $items = array_merge($items, self::getItems($response));
            }
            return $items;
        }
        
        return $responses;
    }

    /**
     * Удаляет объекты KommoObject (списки или элементы списков)
     * @param array|object $amoObjects Массив объектов KommoObject или объект KommoObject
     * @param bool $returnResponses Возвращать массив ответов сервера amoCRM вместо массива параметров сущностей
     * @param string $subdomain Поддомен amoCRM
     * @return array
     * @throws KommoAPIException
     */
    public static function deleteObjects($amoObjects, bool $returnResponses = false, $subdomain = null) :array
    {
        if (! is_array($amoObjects)) {
            $amoObjects = [ $amoObjects ];
        }
        
        $parameters = [];
        foreach ($amoObjects as $object) {
            $params = $object->getParams();
            $id = $params['id'] ?? null;
            if (! $id) {
                throw new KommoAPIException("Для удаления сущности требуется свойство id: " . print_r($params, true));
            }
            $parameters[$object::URL]['delete'][] = $id;
        }

        $responses = [];
        foreach ($parameters as $url => $params) {
            $response = KommoAPI::request($url, 'POST', $params, $subdomain);
            if (empty($response)) {
                throw new KommoAPIException(
                    "Не удалось пакетно удаилить сущности (пустой ответ) по запросу {$url}: " . print_r($params, true)
                );
            }
            $responses[] = $response;
        }

        if (! $returnResponses) {
            $items = [];
            foreach ($responses as $response) {
                $items = array_merge($items, self::getItems($response));
            }
            return $items;
        }
        
        return $responses;
    }
}

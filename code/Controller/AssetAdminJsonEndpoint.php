<?php

namespace SilverStripe\AssetAdmin\Controller;

use InvalidArgumentException;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Forms\DateField;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataList;
use SilverStripe\Security\Security;
use SilverStripe\Versioned\Versioned;
use SilverStripe\ORM\Filterable;

class AssetAdminJsonEndpoint extends Controller
{
    // 'readFileUsage(ids: [ID]!)': '[FileUsage]'
    // 'readDescendantFileCounts(ids: [ID]!)': '[DescendantFileCount]'
    // 'readFiles

    // TODO: should to be live under /admin
    // don't want to use 'extends LeftAndMain' though, cos that
    // creates a model admin of sorts
    // note: session-manager controller endpoint doesn't, so it's ok
    private static array $allowed_actions = [
        'fileusage',
        'descendantfilecounts',
        'files',
    ];

    public function fileusage(HTTPRequest $request): HTTPResponse
    {
        $filter = $this->getFilter($request);
        $idList = $filter['ids'] ?? [];

        /** @var DataList|File[] $files */
        $files = Versioned::get_by_stage(File::class, Versioned::DRAFT)->byIDs($idList);
        if ($files->count() < count($idList)) {
            // Find out which files count not be found
            $missingIds = array_diff($idList, $files->column('ID'));
            throw new \InvalidArgumentException(sprintf(
                '%s items %s are not found',
                File::class,
                implode(', ', $missingIds)
            ));
        }

        $currentUser = Security::getCurrentUser();
        $usage = [];
        foreach ($files as $file) {
            if ($file->canView($currentUser)) {
                $useEntry = ['id' => $file->ID];
                $useEntry['inUseCount'] = $file instanceof Folder ?
                    $file->getFilesInUse()->count():
                    $file->BackLinkTrackingCount();
                $usage[] = $useEntry;
            }
        }

        return $this->createResponse(['usage' => $usage]);
    }

    public function descendantfilecounts(HTTPRequest $request): HTTPResponse
    {
        $filter = $this->getFilter($request);
        $ids = $filter['ids'] ?? [];
        $member = Security::getCurrentUser();

        /** @var DataList|File[] $files */
        $files = Versioned::get_by_stage(File::class, Versioned::DRAFT)->byIDs($ids);
        if ($files->count() < count($ids)) {
            $class = File::class;
            $missingIds = implode(', ', array_diff($ids, $files->column('ID')));
            throw new \InvalidArgumentException("{$class} items {$missingIds} are not found");
        }

        $data = [];
        foreach ($files as $file) {
            if (!$file->canView($member)) {
                continue;
            }
            $data[] = [
                'id' => $file->ID,
                'count' => $file->getDescendantFileCount()
            ];
        }

        return $this->createResponse(['counts' => $data]);
    }

    public function files(HTTPRequest $request): HTTPResponse
    {
        $member = Security::getCurrentUser();

        // Permission checks
        $parent = Folder::singleton();
        if (isset($filter['parentId']) && $filter['parentId'] !== 0) {
            $parent = Folder::get()->byID($filter['parentId']);
            if (!$parent) {
                throw new InvalidArgumentException(sprintf(
                    '%s#%s not found',
                    Folder::class,
                    $filter['parentId']
                ));
            }
        }
        if (!$parent->canView($member)) {
            throw new InvalidArgumentException(sprintf(
                '%s#%s view access not permitted',
                Folder::class,
                $parent->ID
            ));
        }

        if (isset($filter['recursive']) && $filter['recursive']) {
            throw new InvalidArgumentException((
            'The "recursive" flag can only be used for the "children" field'
            ));
        }

        // Filter list
        $filter = $this->getFilter($request);
        $list = Versioned::get_by_stage(File::class, Versioned::DRAFT);
        $list = $this->filterList($list, $filter);

        return $this->createResponse(['files' => $list]);
    }

    private function getFilter(HTTPRequest $request): array
    {
        $allow = [
            'ids',
            'parentId',
            'recursive',
            'name',
            'lastEditedFrom',
            'lastEditedTo',
            'createdFrom',
            'createdTo',
            'appCategory',
            'anyChildId',
        ];
        $filter = [];
        foreach ($request->getVars() as $key => $value) {
            if (!array_key_exists($key, $allow)) {
                continue;
            }
            if ($key === 'ids') {
                $ids = $request->getVar('ids') ?? '';
                $filter['ids'] = explode(',', preg_replace('#[^0-9,]#', '', $ids));
            } else {
                $filter[$key] = $value;
            }
        }
        return $filter;
    }

    private function filterList(DataList $list, array $filter): Filterable
    {
        // ID filtering
        if (isset($filter['id']) && (int)$filter['id'] > 0) {
            $list = $list->filter('ID', $filter['id']);

            if ($list->count() === 0) {
                throw new HTTPResponse_Exception(_t(
                    __CLASS__ . '.FileNotFound',
                    'File or Folder could not be found'
                ));
            }
        } elseif (isset($filter['id']) && (int)$filter['id'] === 0) {
            // Special case for root folder
            $list = new ArrayList([new Folder([
                'ID' => 0,
            ])]);
        }

        // track if search is being applied
        $search = false;

        // Optionally limit search to a folder, supporting recursion
        if (isset($filter['parentId'])) {
            $recursive = !empty($filter['recursive']);

            if (!$recursive) {
                $list = $list->filter('ParentID', $filter['parentId']);
            } elseif ($filter['parentId']) {
                // Note: Simplify parentID = 0 && recursive to no filter at all
                $parents = AssetAdminFile::nestedFolderIDs($filter['parentId']);
                $list = $list->filter('ParentID', $parents);
            }
            $search = true;
        }

        if (!empty($filter['name'])) {
            $list = $list->filterAny(array(
                'Name:PartialMatch' => $filter['name'],
                'Title:PartialMatch' => $filter['name']
            ));
            $search = true;
        }

        // Date filtering last edited
        if (!empty($filter['lastEditedFrom'])) {
            $fromDate = new DateField(null, null, $filter['lastEditedFrom']);
            $list = $list->filter("LastEdited:GreaterThanOrEqual", $fromDate->dataValue().' 00:00:00');
            $search = true;
        }
        if (!empty($filter['lastEditedTo'])) {
            $toDate = new DateField(null, null, $filter['lastEditedTo']);
            $list = $list->filter("LastEdited:LessThanOrEqual", $toDate->dataValue().' 23:59:59');
            $search = true;
        }

        // Date filtering created
        if (!empty($filter['createdFrom'])) {
            $fromDate = new DateField(null, null, $filter['createdFrom']);
            $list = $list->filter("Created:GreaterThanOrEqual", $fromDate->dataValue().' 00:00:00');
            $search = true;
        }
        if (!empty($filter['createdTo'])) {
            $toDate = new DateField(null, null, $filter['createdTo']);
            $list = $list->filter("Created:LessThanOrEqual", $toDate->dataValue().' 23:59:59');
            $search = true;
        }

        // Categories (mapped to extensions through the enum type automatically)
        if (!empty($filter['appCategory'])) {
            $list = $list->filter('Name:EndsWith', $filter['appCategory']);
            $search = true;
        }

        // Filter unknown id by known child if search is not applied
        if (!$search && isset($filter['anyChildId'])) {
            /** @var File $child */
            $child = File::get()->byID($filter['anyChildId']);
            $id = $child ? ($child->ParentID ?: 0) : 0;
            if ($id) {
                $list = $list->filter('ID', $id);
            } else {
                // Special case for root folder, since filter by ID = 0 will return an empty list
                $list = new ArrayList([new Folder([
                    'ID' => 0,
                ])]);
            }
        }
        // Permission checks
        $member = Security::getCurrentUser();
        $list = $list->filterByCallback(function (File $file) use ($member) {
            return $file->canView($member);
        });

        return $list;
    }

    private function createResponse(array $data): HTTPResponse
    {
        $response = HTTPResponse::create();
        $response->setBody(json_encode($data, JSON_FORCE_OBJECT));
        return $response;
    }
}

<?php

declare(strict_types = 1);

namespace Modules\RestAdmin\Interfaces\HTTP;

use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\RestAdmin\Application\Mapping\ItemReader;
use Modules\RestAdmin\Application\Mapping\ListReader;
use Modules\RestAdmin\Application\Spec\SpecRepository;
use Modules\RestAdmin\Application\Spec\SpecStore;
use Modules\RestAdmin\Application\Spec\SpecValidator;
use Modules\RestAdmin\Domain\Model\ServiceSpec;
use Modules\RestAdmin\Infrastructure\Http\CurlTransport;
use Modules\RestAdmin\Infrastructure\Http\HttpTransportInterface;

/**
 * RestAdminServices — the panel's shared objects, built once per request.
 *
 * The Kernel constructs action classes without dependency injection, so actions
 * resolve the repository, the validator, the snapshot store and the transport
 * through these factories (the pattern `CardboardServices` and `AuthServices`
 * already use). `DatabaseConnectionManager` caches the connection, so the
 * repository is the only thing that would otherwise be rebuilt per action.
 *
 * `useTransport()` is the test seam: a panel test queues fixture answers on a
 * `FakeTransport` and no request leaves the machine (plan D4). It is also the
 * seam a future MCP transport would use, which is why it takes the interface
 * rather than the fake class.
 */
final class RestAdminServices
{
    private static ?SpecRepository $repository = null;

    private static ?SpecStore $store = null;

    private static ?SpecValidator $validator = null;

    private static ?HttpTransportInterface $transport = null;

    public static function repository(): SpecRepository
    {
        return self::$repository ??= new SpecRepository(
            DatabaseConnectionManager::instance()->connect('restadmin'),
        );
    }

    public static function store(): SpecStore
    {
        $directory = getenv('RESTADMIN_SPEC_DIR');

        return self::$store ??= new SpecStore(is_string($directory) && '' !== $directory ? $directory : null);
    }

    public static function validator(): SpecValidator
    {
        return self::$validator ??= new SpecValidator();
    }

    public static function transport(): HttpTransportInterface
    {
        return self::$transport ??= new CurlTransport();
    }

    public static function useTransport(HttpTransportInterface $transport): void
    {
        self::$transport = $transport;
    }

    public static function listReader(): ListReader
    {
        return new ListReader(self::transport());
    }

    public static function itemReader(): ItemReader
    {
        return new ItemReader(self::transport());
    }

    /**
     * One write path for the panel: rows first, then the portable snapshot.
     *
     * The rows are the runtime truth (plan F2); the snapshot is the export an
     * operator can move between installs. If the snapshot cannot be written the
     * save has still happened, which is why the caller only sees a failure when
     * the repository refused the aggregate.
     */
    public static function save(ServiceSpec $spec): void
    {
        self::repository()->save($spec);
        self::store()->save($spec);
    }

    public static function forget(ServiceSpec $spec): void
    {
        self::repository()->delete($spec->service->id);
        self::store()->remove($spec->service->id);
    }

    public static function reset(): void
    {
        self::$repository = null;
        self::$store = null;
        self::$validator = null;
        self::$transport = null;
    }
}

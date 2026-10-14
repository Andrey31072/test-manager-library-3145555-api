<?php

use App\Support\ApiResponse;
use App\Support\Jwt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Ramsey\Uuid\Uuid;

function uuid(): string { return Uuid::uuid4()->toString(); }

class BookStockConflict extends RuntimeException {}

function paged($query, Request $request): array
{
    $page = max(1, (int) $request->query('page', 1));
    $size = min(50, max(1, (int) $request->query('size', 20)));
    $total = (clone $query)->count();

    return [
        'items' => $query->offset(($page - 1) * $size)->limit($size)->get(),
        'page' => $page,
        'size' => $size,
        'total' => $total,
    ];
}

function book_response($book): array
{
    return [
        'id' => $book->id,
        'title' => $book->title,
        'author' => $book->author,
        'isbn' => $book->isbn,
        'category' => ['id' => $book->category_id, 'name' => $book->category_name],
        'total_copies' => $book->total_copies,
        'available_copies' => $book->available_copies,
        'cover_key' => $book->cover_key,
    ];
}

Route::prefix('v1')->group(function () {
    Route::get('/health', fn () => ['status' => 'ok']);

    Route::post('/auth/login', function (Request $request) {
        $username = strtolower(trim((string) $request->input('username', '')));
        $user = DB::table('library.user')->where('username', $username)->first();
        if (! $user || ! Hash::check((string) $request->input('password', ''), $user->password_hash)) {
            return ApiResponse::error('INVALID_CREDENTIALS', 'Usuario o clave invalidos.', 401);
        }
        return ['access_token' => Jwt::issue($user->id, $user->role), 'token_type' => 'Bearer', 'expires_in' => 28800, 'role' => $user->role];
    });

    Route::middleware('api.auth:admin')->post('/users', function (Request $request) {
        if ($request->input('role') !== 'librarian') {
            return ApiResponse::error('VALIDATION_ERROR', 'Solo se puede crear el rol librarian.', 422, [['field' => 'role', 'issue' => 'rol invalido']]);
        }
        $username = strtolower(trim((string) $request->input('username')));
        if (DB::table('library.user')->where('username', $username)->exists()) {
            return ApiResponse::error('USERNAME_TAKEN', 'El nombre de usuario ya existe.', 409);
        }
        $row = ['id' => uuid(), 'username' => $username, 'password_hash' => Hash::make((string) $request->input('password')), 'role' => 'librarian'];
        DB::table('library.user')->insert($row);
        return response()->json(['id' => $row['id'], 'username' => $row['username'], 'role' => 'librarian'], 201);
    });

    Route::middleware('api.auth')->get('/categories', fn () => DB::table('library.category')->orderBy('name')->get());

    Route::middleware('api.auth')->get('/books', function (Request $request) {
        $query = DB::table('library.book as b')->join('library.category as c', 'c.id', '=', 'b.category_id')
            ->whereNull('b.deleted_at')->select('b.*', 'c.name as category_name')->orderBy('b.title');
        if ($request->filled('q')) {
            $q = '%'.strtolower((string) $request->query('q')).'%';
            $query->where(fn ($w) => $w->whereRaw('lower(b.title) like ?', [$q])->orWhereRaw('lower(b.author) like ?', [$q]));
        }
        if ($request->filled('category_id')) {
            $query->where('b.category_id', $request->query('category_id'));
        }
        $page = paged($query, $request);
        $page['items'] = $page['items']->map(fn ($book) => book_response($book));
        return $page;
    });

    Route::middleware('api.auth')->get('/books/{id}', function (string $id) {
        $book = DB::table('library.book as b')->join('library.category as c', 'c.id', '=', 'b.category_id')
            ->where('b.id', $id)->whereNull('b.deleted_at')->select('b.*', 'c.name as category_name')->first();
        return $book ? book_response($book) : ApiResponse::error('NOT_FOUND', 'Recurso no encontrado.', 404);
    });

    Route::middleware('api.auth:admin')->post('/books', function (Request $request) {
        $isbn = (string) $request->input('isbn');
        if (! preg_match('/^\d{13}$/', $isbn) || (int) $request->input('total_copies') < 1) {
            return ApiResponse::error('VALIDATION_ERROR', 'Datos del libro invalidos.', 422);
        }
        if (DB::table('library.book')->where('isbn', $isbn)->whereNull('deleted_at')->exists()) {
            return ApiResponse::error('ISBN_TAKEN', 'El ISBN ya existe.', 409);
        }
        $row = ['id' => uuid(), 'title' => trim((string) $request->input('title')), 'author' => trim((string) $request->input('author')), 'isbn' => $isbn, 'category_id' => (string) $request->input('category_id'), 'total_copies' => (int) $request->input('total_copies'), 'available_copies' => (int) $request->input('total_copies'), 'cover_key' => null];
        DB::table('library.book')->insert($row);
        $book = DB::table('library.book as b')->join('library.category as c', 'c.id', '=', 'b.category_id')->where('b.id', $row['id'])->select('b.*', 'c.name as category_name')->first();
        return response()->json(book_response($book), 201);
    });

    Route::middleware('api.auth:admin')->put('/books/{id}', function (Request $request, string $id) {
        $book = DB::table('library.book')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $book) return ApiResponse::error('NOT_FOUND', 'Recurso no encontrado.', 404);
        $newTotal = (int) $request->input('total_copies');
        $available = (int) $book->available_copies + ($newTotal - (int) $book->total_copies);
        if ($newTotal < 1 || $available < 0) return ApiResponse::error('VALIDATION_ERROR', 'El total deja disponibles negativos.', 422);
        DB::table('library.book')->where('id', $id)->update(['title' => trim((string) $request->input('title')), 'author' => trim((string) $request->input('author')), 'category_id' => (string) $request->input('category_id'), 'total_copies' => $newTotal, 'available_copies' => $available]);
        return DB::table('library.book')->where('id', $id)->first();
    });

    Route::middleware('api.auth:admin')->delete('/books/{id}', function (string $id) {
        if (DB::table('library.loan_item')->where('book_id', $id)->whereNull('returned_at')->exists()) {
            return ApiResponse::error('BOOK_HAS_OPEN_LOANS', 'El libro tiene prestamos abiertos.', 409);
        }
        return DB::table('library.book')->where('id', $id)->whereNull('deleted_at')->update(['deleted_at' => now()->toISOString()])
            ? response('', 204) : ApiResponse::error('NOT_FOUND', 'Recurso no encontrado.', 404);
    });

    Route::middleware('api.auth')->get('/books/{id}/cover', function (string $id) {
        $key = DB::table('library.book')->where('id', $id)->whereNull('deleted_at')->value('cover_key');
        if (! $key || ! Storage::disk('local')->exists($key)) return ApiResponse::error('NOT_FOUND', 'Recurso no encontrado.', 404);
        return response()->file(Storage::disk('local')->path($key));
    });

    Route::middleware('api.auth:admin')->put('/books/{id}/cover', function (Request $request, string $id) {
        $request->validate(['cover' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:2048']]);
        if (! DB::table('library.book')->where('id', $id)->whereNull('deleted_at')->exists()) return ApiResponse::error('NOT_FOUND', 'Recurso no encontrado.', 404);
        $key = $request->file('cover')->store('covers');
        DB::table('library.book')->where('id', $id)->update(['cover_key' => $key]);
        return ['cover_key' => $key];
    });

    Route::middleware('api.auth:admin')->delete('/books/{id}/cover', function (string $id) {
        $book = DB::table('library.book')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $book) return ApiResponse::error('NOT_FOUND', 'Recurso no encontrado.', 404);
        if ($book->cover_key) Storage::disk('local')->delete($book->cover_key);
        DB::table('library.book')->where('id', $id)->update(['cover_key' => null]);
        return response('', 204);
    });

    Route::middleware('api.auth:admin,librarian')->post('/members', function (Request $request) {
        if (DB::table('library.member')->where('document_number', $request->input('document_number'))->exists()) {
            return ApiResponse::error('DOCUMENT_TAKEN', 'El documento ya existe.', 409);
        }
        $row = ['id' => uuid(), 'full_name' => trim((string) $request->input('full_name')), 'document_number' => trim((string) $request->input('document_number'))];
        DB::table('library.member')->insert($row);
        return response()->json($row, 201);
    });

    Route::middleware('api.auth:admin,librarian')->get('/members', function (Request $request) {
        $query = DB::table('library.member')->orderBy('full_name');
        if ($request->filled('q')) {
            $q = '%'.strtolower((string) $request->query('q')).'%';
            $query->where(fn ($w) => $w->whereRaw('lower(full_name) like ?', [$q])->orWhereRaw('lower(document_number) like ?', [$q]));
        }
        return paged($query, $request);
    });

    Route::middleware('api.auth:admin,librarian')->get('/members/{id}', function (string $id) {
        $member = DB::table('library.member')->where('id', $id)->first();
        if (! $member) return ApiResponse::error('NOT_FOUND', 'Recurso no encontrado.', 404);
        $member->active_loans = DB::table('library.loan as l')->join('library.loan_item as li', 'li.loan_id', '=', 'l.id')->where('l.member_id', $id)->whereNull('li.returned_at')->count();
        return (array) $member;
    });

    Route::middleware('api.auth:admin,librarian')->post('/loans', function (Request $request) {
        $items = collect($request->input('items', []))->pluck('book_id')->values();
        if ($items->count() < 1 || $items->count() > 3 || $items->duplicates()->isNotEmpty()) {
            return ApiResponse::error($items->duplicates()->isNotEmpty() ? 'DUPLICATE_BOOK_IN_LOAN' : 'VALIDATION_ERROR', 'Prestamo invalido.', 422);
        }
        $memberId = (string) $request->input('member_id');
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $openItems = DB::table('library.loan as l')->join('library.loan_item as li', 'li.loan_id', '=', 'l.id')->where('l.member_id', $memberId)->whereNull('li.returned_at')->get();
        if ($openItems->contains(fn ($item) => new DateTimeImmutable($item->due_at) < $now)) return ApiResponse::error('MEMBER_HAS_OVERDUE', 'El socio tiene libros vencidos.', 409);
        if ($openItems->count() + $items->count() > 3) return ApiResponse::error('MEMBER_LIMIT_EXCEEDED', 'El socio supera el limite de libros sin devolver.', 422);
        try {
            return DB::transaction(function () use ($request, $items, $memberId, $now) {
            $books = DB::table('library.book as b')->join('library.category as c', 'c.id', '=', 'b.category_id')->whereIn('b.id', $items)->whereNull('b.deleted_at')->select('b.*', 'c.name as category_name')->get()->keyBy('id');
            if ($books->count() !== $items->count() || $books->contains(fn ($book) => $book->available_copies < 1)) return ApiResponse::error('BOOK_NOT_AVAILABLE', 'No quedan ejemplares disponibles.', 409);
            $loanId = uuid();
            DB::table('library.loan')->insert(['id' => $loanId, 'member_id' => $memberId, 'loaned_by_user_id' => $request->attributes->get('auth_user')->id, 'loaned_at' => $now->format('Y-m-d\TH:i:s\Z')]);
            $loanItems = [];
            foreach ($items as $bookId) {
                $book = $books[$bookId];
                $updated = DB::table('library.book')->where('id', $bookId)->where('available_copies', '>', 0)->decrement('available_copies');
                if ($updated !== 1) throw new BookStockConflict();
                $loanItems[] = ['id' => uuid(), 'loan_id' => $loanId, 'book_id' => $bookId, 'book_title' => $book->title, 'isbn' => $book->isbn, 'category_name' => $book->category_name, 'due_at' => $now->modify('+14 days')->format('Y-m-d\TH:i:s\Z'), 'returned_at' => null];
            }
            DB::table('library.loan_item')->insert($loanItems);
            return response()->json(['id' => $loanId, 'member_id' => $memberId, 'loaned_at' => $now->format('Y-m-d\TH:i:s\Z'), 'status' => 'open', 'items' => $loanItems], 201);
            });
        } catch (BookStockConflict) {
            return ApiResponse::error('BOOK_NOT_AVAILABLE', 'No quedan ejemplares disponibles.', 409);
        }
    });

    Route::middleware('api.auth:admin,librarian')->get('/loans/overdue', function () {
        $rows = DB::table('library.loan as l')->join('library.loan_item as li', 'li.loan_id', '=', 'l.id')->join('library.member as m', 'm.id', '=', 'l.member_id')
            ->whereNull('li.returned_at')->where('li.due_at', '<', now()->toISOString())->select('l.id as loan_id', 'li.id as item_id', 'li.book_title', 'm.full_name as member_name', 'li.due_at')->get();
        return ['items' => $rows->map(function ($row) { $row->days_late = (new DateTimeImmutable($row->due_at))->diff(new DateTimeImmutable('now', new DateTimeZone('UTC')))->days; return $row; })->sortByDesc('days_late')->values()];
    });

    Route::middleware('api.auth:admin,librarian')->get('/loans', function (Request $request) {
        $query = DB::table('library.loan')->orderByDesc('loaned_at');
        if ($request->filled('member_id')) $query->where('member_id', $request->query('member_id'));
        if ($request->filled('from')) $query->where('loaned_at', '>=', $request->query('from'));
        if ($request->filled('to')) $query->where('loaned_at', '<=', $request->query('to'));
        return paged($query, $request);
    });

    Route::middleware('api.auth:admin,librarian')->get('/loans/{id}', function (string $id) {
        $loan = DB::table('library.loan')->where('id', $id)->first();
        if (! $loan) return ApiResponse::error('NOT_FOUND', 'Recurso no encontrado.', 404);
        $items = DB::table('library.loan_item')->where('loan_id', $id)->get();
        $open = $items->whereNull('returned_at');
        $loan->status = $open->isEmpty() ? 'closed' : ($open->contains(fn ($item) => new DateTimeImmutable($item->due_at) < new DateTimeImmutable('now', new DateTimeZone('UTC'))) ? 'overdue' : 'open');
        $loan->items = $items;
        return (array) $loan;
    });

    Route::middleware('api.auth:admin,librarian')->post('/loans/{loan_id}/items/{item_id}/return', function (string $loanId, string $itemId) {
        return DB::transaction(function () use ($loanId, $itemId) {
            $item = DB::table('library.loan_item')->where('id', $itemId)->where('loan_id', $loanId)->lockForUpdate()->first();
            if (! $item) return ApiResponse::error('NOT_FOUND', 'Recurso no encontrado.', 404);
            if ($item->returned_at !== null) return ApiResponse::error('ITEM_ALREADY_RETURNED', 'La linea ya fue devuelta.', 409);
            $returnedAt = now()->toISOString();
            DB::table('library.loan_item')->where('id', $itemId)->update(['returned_at' => $returnedAt]);
            DB::table('library.book')->where('id', $item->book_id)->increment('available_copies');
            $item->returned_at = $returnedAt;
            return (array) $item;
        });
    });

    Route::middleware('api.auth:admin')->get('/reports/most-borrowed', function (Request $request) {
        $from = (string) $request->query('from');
        $to = (string) $request->query('to');
        if ($from === '' || $to === '' || $to < $from) return ApiResponse::error('INVALID_DATE_RANGE', 'El rango de fechas es invalido.', 422);
        $rows = DB::table('library.loan as l')->join('library.loan_item as li', 'li.loan_id', '=', 'l.id')->whereDate('l.loaned_at', '>=', $from)->whereDate('l.loaned_at', '<=', $to)->groupBy('li.book_id', 'li.book_title', 'li.category_name')->select('li.book_id', 'li.book_title', 'li.category_name', DB::raw('count(*) as times_borrowed'))->orderByDesc('times_borrowed')->orderBy('li.book_title')->get();
        return ['from' => $from, 'to' => $to, 'rows' => $rows];
    });
});

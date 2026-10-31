<?php

declare(strict_types=1);

namespace FastGtp;

/**
 * One engine process on the server. Each method sends one request.
 *
 * Keep $id to come back to the session later with Client::session().
 */
final class Session
{
    private readonly string $path;

    /** @internal Use Client::createSession() or Client::session(). */
    public function __construct(
        private readonly Http $http,
        public readonly string $id,
    ) {
        $this->path = '/sessions/' . Http::segment($id);
    }

    public function info(): SessionInfo
    {
        return SessionInfo::fromArray($this->http->json('GET', $this->path));
    }

    public function board(): Board
    {
        return Board::fromArray($this->http->json('GET', $this->path . '/board'));
    }

    /** Replace the board with an empty one. */
    public function setBoard(int $size, float $komi): Board
    {
        $body = ['size' => $size, 'komi' => $komi];

        return Board::fromArray($this->http->json('PUT', $this->path . '/board', $body));
    }

    /**
     * Replace the board with a position from an SGF game record.
     *
     * @param int|null $moveCount Load only this many moves; 0 gives the setup position.
     */
    public function loadSgf(string $sgf, ?int $moveCount = null): Board
    {
        $body = ['sgf' => $sgf];
        if ($moveCount !== null) {
            $body['move_count'] = $moveCount;
        }

        return Board::fromArray($this->http->json('PUT', $this->path . '/board', $body));
    }

    /** Play a move. An illegal move throws a ProblemException of type "illegal-move". */
    public function play(Color $color, string $vertex): Move
    {
        $body = ['color' => $color->value, 'vertex' => $vertex];

        return Move::fromArray($this->http->json('POST', $this->path . '/moves', $body));
    }

    /** Let the engine choose and play a move. It may resign. */
    public function generateMove(Color $color): Move
    {
        return Move::fromArray($this->http->json('POST', $this->path . '/moves', ['color' => $color->value]));
    }

    /** Take back the last move. */
    public function undo(): void
    {
        $this->http->send('DELETE', $this->path . '/moves/last');
    }

    /** Stop the engine. */
    public function delete(): void
    {
        $this->http->send('DELETE', $this->path);
    }
}

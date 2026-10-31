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
        private readonly HttpTransporter $transporter,
        public readonly string $id,
    ) {
        $this->path = '/sessions/' . HttpTransporter::segment($id);
    }

    public function info(): SessionInfo
    {
        return $this->transporter->read('GET', $this->path, null, SessionInfo::fromArray(...));
    }

    public function board(): Board
    {
        return $this->transporter->read('GET', $this->path . '/board', null, Board::fromArray(...));
    }

    /** Replace the board with an empty one. */
    public function setBoard(int $size, float $komi): Board
    {
        $body = ['size' => $size, 'komi' => $komi];

        return $this->transporter->read('PUT', $this->path . '/board', $body, Board::fromArray(...));
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

        return $this->transporter->read('PUT', $this->path . '/board', $body, Board::fromArray(...));
    }

    /** Play a move. An illegal move throws a FastGtpException whose is('illegal-move') is true. */
    public function play(Color $color, string $vertex): Move
    {
        $body = ['color' => $color->value, 'vertex' => $vertex];

        return $this->transporter->read('POST', $this->path . '/moves', $body, Move::fromArray(...));
    }

    /** Let the engine choose and play a move. It may resign. */
    public function generateMove(Color $color): Move
    {
        return $this->transporter->read('POST', $this->path . '/moves', ['color' => $color->value], Move::fromArray(...));
    }

    /** Take back the last move. */
    public function undo(): void
    {
        $this->transporter->send('DELETE', $this->path . '/moves/last');
    }

    /** Stop the engine. */
    public function delete(): void
    {
        $this->transporter->send('DELETE', $this->path);
    }
}

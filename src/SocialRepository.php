<?php
declare(strict_types=1);

final class SocialRepository {
    public function __construct(private PDO $pdo) {}

    public function areBlocked(int $a, int $b): bool {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM user_blocks WHERE unblocked_at IS NULL '
            . 'AND ((blocker_id=:a1 AND blocked_id=:b1) OR (blocker_id=:b2 AND blocked_id=:a2)) LIMIT 1'
        );
        $stmt->execute(['a1' => $a, 'b1' => $b, 'b2' => $b, 'a2' => $a]);
        return (bool) $stmt->fetchColumn();
    }

    public function sendFriendRequest(int $requester, int $target, string $message): string {
        if ($requester === $target) throw new InvalidArgumentException('Vous ne pouvez pas vous ajouter vous-même.');
        if ($this->areBlocked($requester, $target)) throw new RuntimeException('Joueur indisponible.');
        if (mb_strlen($message) > 300) throw new InvalidArgumentException('Le message est limité à 300 caractères.');
        $targetStmt = $this->pdo->prepare('SELECT friend_requests_enabled, is_ai, ai_friend_policy FROM users WHERE id=:id');
        $targetStmt->execute(['id' => $target]);
        $preferences = $targetStmt->fetch(PDO::FETCH_ASSOC);
        if (!$preferences || empty($preferences['friend_requests_enabled']) || ($preferences['is_ai'] && $preferences['ai_friend_policy'] === 'reject')) {
            throw new RuntimeException('Joueur indisponible.');
        }
        $countStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM friendships WHERE status='pending' AND requester_id=:requester AND created_at >= CURRENT_TIMESTAMP - INTERVAL 1 HOUR"
        );
        $countStmt->execute(['requester' => $requester]);
        if ((int) $countStmt->fetchColumn() >= 10) throw new RuntimeException('Limite de demandes atteinte. Réessayez plus tard.');
        $pendingStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM friendships WHERE status='pending' AND (user_low_id=:id1 OR user_high_id=:id2)"
        );
        $pendingStmt->execute(['id1' => $target, 'id2' => $target]);
        if ((int) $pendingStmt->fetchColumn() >= 50) throw new RuntimeException('Joueur indisponible.');
        [$low, $high] = $this->pair($requester, $target);
        $existing = $this->pdo->prepare('SELECT status, requester_id, created_at, declined_at FROM friendships WHERE user_low_id=:low AND user_high_id=:high');
        $existing->execute(['low' => $low, 'high' => $high]);
        $relation = $existing->fetch(PDO::FETCH_ASSOC);
        if ($relation && $relation['status'] === 'accepted') throw new RuntimeException('Ce joueur est déjà votre ami.');
        if ($relation && $relation['status'] === 'declined') {
            $declinedAt = strtotime((string) ($relation['declined_at'] ?? $relation['created_at']));
            if ($declinedAt && $declinedAt > time() - 86400) {
                throw new RuntimeException('Cette demande a été refusée récemment. Réessayez après 24 heures.');
            }
            $this->pdo->prepare('DELETE FROM friendships WHERE user_low_id=:low AND user_high_id=:high')->execute(['low' => $low, 'high' => $high]);
            $relation = false;
        }
        if ($relation) {
            if ((int) $relation['requester_id'] === $requester) throw new RuntimeException('Une demande est déjà en attente.');
            return $this->acceptFriendRequest($requester, $target) ? 'accepted' : 'pending';
        }
        $autoAccept = !empty($preferences['is_ai']) && $preferences['ai_friend_policy'] === 'auto_accept';
        $stmt = $this->pdo->prepare(
            'INSERT INTO friendships (user_low_id,user_high_id,requester_id,status,message,accepted_at) '
            . 'VALUES (:low,:high,:requester,:status,:message,' . ($autoAccept ? 'CURRENT_TIMESTAMP' : 'NULL') . ')'
        );
        $stmt->execute(['low' => $low, 'high' => $high, 'requester' => $requester, 'status' => $autoAccept ? 'accepted' : 'pending', 'message' => $message ?: null]);
        if ($autoAccept) $this->notify($requester, $target, 'friend_accepted', null);
        return $autoAccept ? 'accepted' : 'pending';
    }

    public function acceptFriendRequest(int $user, int $requester): bool {
        [$low, $high] = $this->pair($user, $requester);
        $stmt = $this->pdo->prepare(
            "UPDATE friendships SET status='accepted', accepted_at=CURRENT_TIMESTAMP WHERE user_low_id=:low AND user_high_id=:high AND requester_id=:requester AND status='pending'"
        );
        $stmt->execute(['low' => $low, 'high' => $high, 'requester' => $requester]);
        if (!$stmt->rowCount()) return false;
        $this->deleteNotificationBetween($user, $requester, 'friend_request');
        $this->notify($requester, $user, 'friend_accepted', null);
        return true;
    }

    public function declineFriendRequest(int $user, int $requester): bool {
        [$low, $high] = $this->pair($user, $requester);
        $stmt = $this->pdo->prepare(
            "UPDATE friendships SET status='declined', declined_at=CURRENT_TIMESTAMP WHERE user_low_id=:low AND user_high_id=:high AND requester_id=:requester AND status='pending'"
        );
        $stmt->execute(['low' => $low, 'high' => $high, 'requester' => $requester]);
        if ($stmt->rowCount()) $this->deleteNotificationBetween($user, $requester, 'friend_request');
        return $stmt->rowCount() > 0;
    }

    public function removeFriend(int $user, int $other): bool {
        [$low, $high] = $this->pair($user, $other);
        $stmt = $this->pdo->prepare("DELETE FROM friendships WHERE user_low_id=:low AND user_high_id=:high AND status='accepted'");
        $stmt->execute(['low' => $low, 'high' => $high]);
        if (!$stmt->rowCount()) return false;
        $this->notify($other, $user, 'friend_removed', null);
        return true;
    }

    public function block(int $blocker, int $blocked): void {
        if ($blocker === $blocked) throw new InvalidArgumentException('Vous ne pouvez pas vous bloquer vous-même.');
        $this->pdo->beginTransaction();
        try {
            [$low, $high] = $this->pair($blocker, $blocked);
            $this->pdo->prepare('DELETE FROM friendships WHERE user_low_id=:low AND user_high_id=:high')->execute(['low' => $low, 'high' => $high]);
            $this->pdo->prepare(
                'DELETE FROM social_notifications WHERE (user_id=:blocker1 AND actor_id=:blocked1) OR (user_id=:blocked2 AND actor_id=:blocker2)'
            )->execute(['blocker1' => $blocker, 'blocked1' => $blocked, 'blocked2' => $blocked, 'blocker2' => $blocker]);
            $exists = $this->pdo->prepare('SELECT 1 FROM user_blocks WHERE blocker_id=:blocker AND blocked_id=:blocked AND unblocked_at IS NULL');
            $exists->execute(['blocker' => $blocker, 'blocked' => $blocked]);
            if (!$exists->fetchColumn()) {
                $this->pdo->prepare('INSERT INTO user_blocks (blocker_id,blocked_id) VALUES (:blocker,:blocked)')->execute(['blocker' => $blocker, 'blocked' => $blocked]);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function unblock(int $blocker, int $blocked): bool {
        $stmt = $this->pdo->prepare('UPDATE user_blocks SET unblocked_at=CURRENT_TIMESTAMP WHERE blocker_id=:blocker AND blocked_id=:blocked AND unblocked_at IS NULL');
        $stmt->execute(['blocker' => $blocker, 'blocked' => $blocked]);
        return $stmt->rowCount() > 0;
    }

    public function socialState(int $user, array $onlineUserIds): array {
        $this->purgeExpiredHistory();
        // Les demandes sont affichées depuis friendships et ne doivent pas être
        // dupliquées dans le fil de notifications.
        $this->pdo->prepare("DELETE FROM social_notifications WHERE user_id=:user AND type='friend_request'")->execute(['user' => $user]);
        $friendStmt = $this->pdo->prepare(
            "SELECT u.id,u.username,u.is_ai,u.last_active FROM friendships f JOIN users u ON u.id=IF(f.user_low_id=:uid1,f.user_high_id,f.user_low_id) WHERE (f.user_low_id=:uid2 OR f.user_high_id=:uid3) AND f.status='accepted' ORDER BY u.username"
        );
        $friendStmt->execute(['uid1' => $user, 'uid2' => $user, 'uid3' => $user]);
        $friends = $friendStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($friends as &$friend) $friend['online'] = in_array((int) $friend['id'], $onlineUserIds, true);
        unset($friend);
        $incoming = $this->requests($user, false);
        $outgoing = $this->requests($user, true);
        $blockStmt = $this->pdo->prepare('SELECT u.id,u.username,b.created_at FROM user_blocks b JOIN users u ON u.id=b.blocked_id WHERE b.blocker_id=:user AND b.unblocked_at IS NULL ORDER BY u.username');
        $blockStmt->execute(['user' => $user]);
        $notifications = $this->pdo->prepare('SELECT n.id,n.type,n.message,n.created_at,n.read_at,u.username AS actor FROM social_notifications n LEFT JOIN users u ON u.id=n.actor_id WHERE n.user_id=:user AND n.read_at IS NULL ORDER BY n.created_at DESC LIMIT 50');
        $notifications->execute(['user' => $user]);
        $preferences = $this->pdo->prepare('SELECT friend_requests_enabled FROM users WHERE id=:user');
        $preferences->execute(['user' => $user]);
        return ['friends' => $friends, 'incoming' => $incoming, 'outgoing' => $outgoing, 'blocked' => $blockStmt->fetchAll(PDO::FETCH_ASSOC), 'notifications' => $notifications->fetchAll(PDO::FETCH_ASSOC), 'friendRequestsEnabled' => (bool) $preferences->fetchColumn()];
    }

    public function markNotificationsRead(int $user): void {
        $this->pdo->prepare('UPDATE social_notifications SET read_at=COALESCE(read_at,CURRENT_TIMESTAMP) WHERE user_id=:user')->execute(['user' => $user]);
    }

    public function setRequestsEnabled(int $user, bool $enabled): void {
        $this->pdo->prepare('UPDATE users SET friend_requests_enabled=:enabled WHERE id=:user')->execute(['enabled' => (int) $enabled, 'user' => $user]);
    }

    public function friendIds(int $user): array {
        $stmt = $this->pdo->prepare("SELECT IF(user_low_id=:u1,user_high_id,user_low_id) FROM friendships WHERE (user_low_id=:u2 OR user_high_id=:u3) AND status='accepted'");
        $stmt->execute(['u1' => $user, 'u2' => $user, 'u3' => $user]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function purgeExpiredHistory(): void {
        $this->pdo->exec("DELETE FROM user_blocks WHERE unblocked_at IS NOT NULL AND unblocked_at < CURRENT_TIMESTAMP - INTERVAL 90 DAY");
        $this->pdo->exec("DELETE FROM friendships WHERE status='declined' AND declined_at < CURRENT_TIMESTAMP - INTERVAL 24 HOUR");
        $this->pdo->exec("DELETE FROM social_notifications WHERE created_at < CURRENT_TIMESTAMP - INTERVAL 30 DAY");
    }

    private function requests(int $user, bool $sent): array {
        $operator = $sent ? '=' : '<>';
        $stmt = $this->pdo->prepare(
            "SELECT u.id,u.username,u.is_ai,f.message,f.created_at FROM friendships f JOIN users u ON u.id=IF(f.user_low_id=:uid1,f.user_high_id,f.user_low_id) WHERE (f.user_low_id=:uid2 OR f.user_high_id=:uid3) AND f.status='pending' AND f.requester_id {$operator} :uid4 ORDER BY f.created_at DESC"
        );
        $stmt->execute(['uid1' => $user, 'uid2' => $user, 'uid3' => $user, 'uid4' => $user]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function notify(int $user, int $actor, string $type, ?string $message): void {
        $this->pdo->prepare('INSERT INTO social_notifications (user_id,actor_id,type,message) VALUES (:user,:actor,:type,:message)')->execute(['user' => $user, 'actor' => $actor, 'type' => $type, 'message' => $message ?: null]);
    }

    private function deleteNotificationBetween(int $user, int $actor, string $type): void {
        $this->pdo->prepare('DELETE FROM social_notifications WHERE user_id=:user AND actor_id=:actor AND type=:type')
            ->execute(['user' => $user, 'actor' => $actor, 'type' => $type]);
    }

    private function pair(int $a, int $b): array { return $a < $b ? [$a, $b] : [$b, $a]; }
}

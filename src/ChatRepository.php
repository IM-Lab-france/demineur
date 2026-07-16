<?php
declare(strict_types=1);

final class ChatRepository {
    public function __construct(private PDO $pdo, private SocialRepository $social) {}

    public function directConversation(int $user, int $other): int {
        if ($user === $other) throw new InvalidArgumentException('Conversation invalide.');
        if ($this->social->areBlocked($user, $other)) throw new RuntimeException('Joueur indisponible.');
        if (!in_array($other, $this->social->friendIds($user), true)) throw new RuntimeException('La messagerie privée est réservée aux amis.');
        [$low, $high] = $user < $other ? [$user, $other] : [$other, $user];
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("INSERT INTO chat_conversations(kind,user_low_id,user_high_id) VALUES('direct',:low,:high) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),closed_at=NULL,purge_after=NULL");
            $stmt->execute(['low'=>$low,'high'=>$high]);
            $id = (int) $this->pdo->lastInsertId();
            foreach ([$user,$other] as $participant) $this->pdo->prepare('INSERT INTO chat_participants(conversation_id,user_id,hidden_at) VALUES(:conversation,:user,NULL) ON DUPLICATE KEY UPDATE hidden_at=NULL')->execute(['conversation'=>$id,'user'=>$participant]);
            $this->pdo->commit();
            return $id;
        } catch (Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }

    public function gameConversation(string $gameId, array $users): int {
        $stmt = $this->pdo->prepare("INSERT INTO chat_conversations(kind,game_id) VALUES('game',:game) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),closed_at=NULL,purge_after=NULL");
        $stmt->execute(['game'=>$gameId]); $id=(int)$this->pdo->lastInsertId();
        foreach (array_unique(array_map('intval',$users)) as $user) if ($user>0) $this->pdo->prepare('INSERT IGNORE INTO chat_participants(conversation_id,user_id) VALUES(:conversation,:user)')->execute(['conversation'=>$id,'user'=>$user]);
        return $id;
    }
    public function addGameParticipant(string $gameId,int $user,bool $canWrite): int { $stmt=$this->pdo->prepare("SELECT id FROM chat_conversations WHERE kind='game' AND game_id=:game");$stmt->execute(['game'=>$gameId]);$id=(int)$stmt->fetchColumn();if(!$id)throw new RuntimeException('Chat de partie indisponible.');$this->pdo->prepare('INSERT INTO chat_participants(conversation_id,user_id,can_write) VALUES(:conversation,:user,:write) ON DUPLICATE KEY UPDATE can_write=:write2,hidden_at=NULL')->execute(['conversation'=>$id,'user'=>$user,'write'=>(int)$canWrite,'write2'=>(int)$canWrite]);return$id; }

    public function listForUser(int $user): array {
        $this->purge();
        $stmt=$this->pdo->prepare("SELECT c.id,c.kind,c.game_id,c.last_message_at,p.muted,p.last_read_message_id,IF(c.kind='direct',u.id,NULL) other_id,IF(c.kind='direct',u.username,CONCAT('Partie ',LEFT(c.game_id,8))) title,IF(p.muted=1,0,(SELECT COUNT(*) FROM chat_messages m WHERE m.conversation_id=c.id AND m.id>COALESCE(p.last_read_message_id,0) AND (m.sender_id IS NULL OR m.sender_id<>:u1))) unread,(SELECT IF(m.deleted_at IS NULL,m.body,'Message supprimé') FROM chat_messages m WHERE m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1) preview FROM chat_participants p JOIN chat_conversations c ON c.id=p.conversation_id LEFT JOIN users u ON c.kind='direct' AND u.id=IF(c.user_low_id=:u2,c.user_high_id,c.user_low_id) WHERE p.user_id=:u3 AND p.hidden_at IS NULL AND c.closed_at IS NULL ORDER BY COALESCE(c.last_message_at,c.created_at) DESC");
        $stmt->execute(['u1'=>$user,'u2'=>$user,'u3'=>$user]); return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function messages(int $user,int $conversation,int $limit=100): array {
        $this->assertAccess($user,$conversation);
        $stmt=$this->pdo->prepare("SELECT m.id,m.sender_id,u.username,m.message_type,IF(m.deleted_at IS NULL,m.body,NULL) body,m.deleted_at,m.created_at,(SELECT GROUP_CONCAT(CONCAT(r.reaction,':',r.user_id) SEPARATOR ',') FROM chat_reactions r WHERE r.message_id=m.id) reactions FROM chat_messages m LEFT JOIN users u ON u.id=m.sender_id WHERE m.conversation_id=:conversation ORDER BY m.id DESC LIMIT ".max(1,min(200,$limit)));
        $stmt->execute(['conversation'=>$conversation]); return array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function send(int $user,int $conversation,string $body): array {
        $chat=$this->assertAccess($user,$conversation);
        if (empty($chat['can_write'])) throw new RuntimeException('Ce chat est en lecture seule.');
        if ($chat['kind']==='direct' && $this->social->areBlocked($user,(int)$chat['other_id'])) throw new RuntimeException('Joueur indisponible.');
        $body=trim($body); if ($body==='' || mb_strlen($body)>500) throw new InvalidArgumentException('Le message doit contenir entre 1 et 500 caractères.');
        $stmt=$this->pdo->prepare("INSERT INTO chat_messages(conversation_id,sender_id,body) VALUES(:conversation,:sender,:body)"); $stmt->execute(['conversation'=>$conversation,'sender'=>$user,'body'=>$body]); $id=(int)$this->pdo->lastInsertId();
        $this->pdo->prepare('UPDATE chat_conversations SET last_message_at=CURRENT_TIMESTAMP WHERE id=:id')->execute(['id'=>$conversation]);
        $this->pdo->prepare('UPDATE chat_participants SET hidden_at=NULL WHERE conversation_id=:id')->execute(['id'=>$conversation]);
        return ['id'=>$id,'conversation_id'=>$conversation,'sender_id'=>$user,'body'=>$body,'message_type'=>'user','created_at'=>date('Y-m-d H:i:s'),'deleted_at'=>null,'reactions'=>null];
    }

    public function markRead(int $user,int $conversation,int $message): void { $this->assertAccess($user,$conversation); $this->pdo->prepare('UPDATE chat_participants SET last_read_message_id=GREATEST(COALESCE(last_read_message_id,0),:message) WHERE conversation_id=:conversation AND user_id=:user')->execute(['message'=>$message,'conversation'=>$conversation,'user'=>$user]); }
    public function setMuted(int $user,int $conversation,bool $muted): void { $this->assertAccess($user,$conversation); $this->pdo->prepare('UPDATE chat_participants SET muted=:muted WHERE conversation_id=:conversation AND user_id=:user')->execute(['muted'=>(int)$muted,'conversation'=>$conversation,'user'=>$user]); }
    public function hide(int $user,int $conversation): void { $this->assertAccess($user,$conversation); $this->pdo->prepare('UPDATE chat_participants SET hidden_at=CURRENT_TIMESTAMP WHERE conversation_id=:conversation AND user_id=:user')->execute(['conversation'=>$conversation,'user'=>$user]); }
    public function deleteMessage(int $user,int $message): int { $stmt=$this->pdo->prepare('UPDATE chat_messages SET body=NULL,deleted_at=CURRENT_TIMESTAMP WHERE id=:message AND sender_id=:user AND deleted_at IS NULL'); $stmt->execute(['message'=>$message,'user'=>$user]); if(!$stmt->rowCount()) throw new RuntimeException('Message introuvable.'); return $message; }
    public function react(int $user,int $message,string $reaction): int { if(!in_array($reaction,['👍','👎','😂','😮','🎉'],true)) throw new InvalidArgumentException('Réaction invalide.'); $stmt=$this->pdo->prepare('SELECT conversation_id FROM chat_messages WHERE id=:id');$stmt->execute(['id'=>$message]);$conversation=(int)$stmt->fetchColumn();$this->assertAccess($user,$conversation);$this->pdo->prepare('INSERT INTO chat_reactions(message_id,user_id,reaction) VALUES(:message,:user,:reaction) ON DUPLICATE KEY UPDATE created_at=CURRENT_TIMESTAMP')->execute(['message'=>$message,'user'=>$user,'reaction'=>$reaction]);return $conversation; }
    public function preferences(int $user): array { $stmt=$this->pdo->prepare('SELECT chat_enabled,chat_sound_enabled,chat_layout,chat_position,sound_enabled FROM users WHERE id=:id');$stmt->execute(['id'=>$user]);return $stmt->fetch(PDO::FETCH_ASSOC)?:[]; }
    public function savePreferences(int $user,array $data): void { $layout=in_array($data['layout']??'floating',['floating','docked'],true)?$data['layout']:'floating';$position=json_encode($data['position']??null);$this->pdo->prepare('UPDATE users SET chat_enabled=:enabled,chat_sound_enabled=:sound,chat_layout=:layout,chat_position=:position WHERE id=:id')->execute(['enabled'=>!empty($data['enabled']),'sound'=>!empty($data['sound']),'layout'=>$layout,'position'=>$position,'id'=>$user]); }
    public function setGlobalSound(int $user,bool $enabled): void { $this->pdo->prepare('UPDATE users SET sound_enabled=:enabled WHERE id=:id')->execute(['enabled'=>(int)$enabled,'id'=>$user]); }
    public function participantIds(int $conversation): array { $stmt=$this->pdo->prepare('SELECT user_id FROM chat_participants WHERE conversation_id=:id');$stmt->execute(['id'=>$conversation]);return array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN)); }
    public function systemMessage(int $conversation,string $body): void { $this->pdo->prepare("INSERT INTO chat_messages(conversation_id,message_type,body) VALUES(:conversation,'system',:body)")->execute(['conversation'=>$conversation,'body'=>mb_substr($body,0,500)]);$this->pdo->prepare('UPDATE chat_conversations SET last_message_at=CURRENT_TIMESTAMP WHERE id=:id')->execute(['id'=>$conversation]); }
    public function closeDirect(int $a,int $b,bool $retainThirtyDays): void { [$low,$high]=$a<$b?[$a,$b]:[$b,$a];if($retainThirtyDays)$this->pdo->prepare("UPDATE chat_conversations SET closed_at=CURRENT_TIMESTAMP,purge_after=CURRENT_TIMESTAMP+INTERVAL 30 DAY WHERE kind='direct' AND user_low_id=:low AND user_high_id=:high")->execute(['low'=>$low,'high'=>$high]);else$this->pdo->prepare("DELETE FROM chat_conversations WHERE kind='direct' AND user_low_id=:low AND user_high_id=:high")->execute(['low'=>$low,'high'=>$high]); }
    private function assertAccess(int $user,int $conversation): array { $stmt=$this->pdo->prepare("SELECT c.kind,p.can_write,IF(c.kind='direct',IF(c.user_low_id=:u1,c.user_high_id,c.user_low_id),NULL) other_id FROM chat_conversations c JOIN chat_participants p ON p.conversation_id=c.id WHERE c.id=:conversation AND p.user_id=:u2 AND c.closed_at IS NULL");$stmt->execute(['u1'=>$user,'conversation'=>$conversation,'u2'=>$user]);$chat=$stmt->fetch(PDO::FETCH_ASSOC);if(!$chat)throw new RuntimeException('Conversation indisponible.');return $chat; }
    private function purge(): void { $this->pdo->exec("DELETE FROM chat_conversations WHERE (purge_after IS NOT NULL AND purge_after<CURRENT_TIMESTAMP) OR (kind='direct' AND COALESCE(last_message_at,created_at)<CURRENT_TIMESTAMP-INTERVAL 2 YEAR)"); }
}

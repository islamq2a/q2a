<?php

if (!defined('QA_VERSION')) {
	header('Location: ../../');
	exit;
}

class qa_islamq2a_stats_dashboard
{
	public function match_request($request)
	{
		return $request === 'admin/islamq2a-stats';
	}

	public function process_request($request)
	{
		$qa_content = qa_content_prepare();
		$qa_content['title'] = 'IslamQ2A Stats Dashboard';

		$permit_error = qa_user_permit_error('permit_admin');
		if ($permit_error) {
			$qa_content['error'] = qa_lang_html('main/no_privileges');
			return $qa_content;
		}

		$totals = $this->fetch_totals();
		$periods = $this->fetch_period_counts();
		$ratios = $this->calculate_ratios($totals);
		$timeline = $this->fetch_daily_timeline(30);
		$health = $this->calculate_health_index();
		$avg_first_answer_7 = $this->fetch_avg_first_answer_time(7);
		$avg_first_answer_30 = $this->fetch_avg_first_answer_time(30);

		$qa_content['custom'] = $this->render_dashboard(
			$totals,
			$periods,
			$ratios,
			$timeline,
			$health,
			$avg_first_answer_7,
			$avg_first_answer_30
		);

		return $qa_content;
	}

	private function fetch_totals()
	{
		return array(
			'questions' => $this->fetch_post_count("type='Q'"),
			'answers' => $this->fetch_post_count("type='A'"),
			'comments' => $this->fetch_post_count("type='C'"),
			'users' => $this->fetch_user_count(),
			'duplicate_answered' => $this->fetch_duplicate_answered_count(),
			'duplicate_unanswered' => $this->fetch_duplicate_unanswered_count(),
			'duplicate_total' => $this->fetch_duplicate_total_count(),
			'answered_public' => $this->fetch_answered_public_count(),
			'unanswered' => $this->fetch_unanswered_count(),
			'unanswered_over_7_days' => $this->fetch_unanswered_over_days(7),
		);
	}

	private function fetch_period_counts()
	{
		return array(
			'daily' => $this->fetch_period_post_counts(1),
			'weekly' => $this->fetch_period_post_counts(7),
			'monthly' => $this->fetch_period_post_counts(30),
		);
	}

	private function calculate_ratios($totals)
	{
		$question_count = max(1, (int) $totals['questions']);
		$answer_ratio = $totals['answers'] > 0 ? round(($totals['answers'] / $question_count) * 100, 2) : 0;
		$comment_ratio = $totals['comments'] > 0 ? round(($totals['comments'] / $question_count) * 100, 2) : 0;

		return array(
			'answers_per_question' => $answer_ratio,
			'comments_per_question' => $comment_ratio,
		);
	}

	private function fetch_daily_timeline($days)
	{
		$days = max(1, (int) $days);
		$since = time() - ($days * 86400);

		$created_expr = $this->normalized_created_expression();
		$results = qa_db_read_all_assoc(
			qa_db_query_sub(
				"SELECT DATE($created_expr) AS day,
					SUM(CASE WHEN type='Q' THEN 1 ELSE 0 END) AS questions,
					SUM(CASE WHEN type='A' THEN 1 ELSE 0 END) AS answers,
					SUM(CASE WHEN type='C' THEN 1 ELSE 0 END) AS comments
				 FROM ^posts
				 WHERE ($created_expr) >= FROM_UNIXTIME(#)
				 GROUP BY day
				 ORDER BY day ASC",
				$since
			)
		);

		$indexed = array();
		foreach ($results as $row) {
			$indexed[$row['day']] = $row;
		}

		$timeline = array();
		for ($i = 0; $i < $days; $i++) {
			$day = date('Y-m-d', time() - ($i * 86400));
			$timeline[] = array(
				'day' => $day,
				'questions' => isset($indexed[$day]) ? (int) $indexed[$day]['questions'] : 0,
				'answers' => isset($indexed[$day]) ? (int) $indexed[$day]['answers'] : 0,
				'comments' => isset($indexed[$day]) ? (int) $indexed[$day]['comments'] : 0,
			);
		}

		return $timeline;
	}

	private function fetch_post_count($condition)
	{
		return (int) qa_db_read_one_value(
			qa_db_query_sub(
				"SELECT COUNT(*) FROM ^posts WHERE $condition"
			),
			true
		);
	}

	private function fetch_user_count()
	{
		return (int) qa_db_read_one_value(
			qa_db_query_sub(
				"SELECT COUNT(*) FROM ^users"
			),
			true
		);
	}

	private function fetch_duplicate_answered_count()
	{
		$tag_condition = $this->special_tag_condition();

		return (int) qa_db_read_one_value(
			qa_db_query_sub(
				"SELECT COUNT(*) FROM ^posts
				 WHERE type='Q'
				 AND acount > 0
				 AND ($tag_condition)"
			),
			true
		);
	}

	private function fetch_duplicate_unanswered_count()
	{
		$tag_condition = $this->special_tag_condition();

		return (int) qa_db_read_one_value(
			qa_db_query_sub(
				"SELECT COUNT(*) FROM ^posts
				 WHERE type='Q'
				 AND acount = 0
				 AND ($tag_condition)"
			),
			true
		);
	}

	private function fetch_duplicate_total_count()
	{
		$tag_condition = $this->special_tag_condition();

		return (int) qa_db_read_one_value(
			qa_db_query_sub(
				"SELECT COUNT(*) FROM ^posts
				 WHERE type='Q'
				 AND ($tag_condition)"
			),
			true
		);
	}

	private function fetch_unanswered_count()
	{
		return (int) qa_db_read_one_value(
			qa_db_query_sub(
				"SELECT COUNT(*) FROM ^posts
				 WHERE type='Q'
				 AND acount = 0"
			),
			true
		);
	}

	private function fetch_unanswered_over_days($days)
	{
		$cutoff = time() - ((int) $days * 86400);
		$created_expr = $this->normalized_created_expression();

		return (int) qa_db_read_one_value(
			qa_db_query_sub(
				"SELECT COUNT(*) FROM ^posts
				 WHERE type='Q'
				 AND acount = 0
				 AND ($created_expr) < FROM_UNIXTIME(#)",
				$cutoff
			),
			true
		);
	}

	private function fetch_answered_public_count()
	{
		$tag_condition = $this->special_tag_condition();

		return (int) qa_db_read_one_value(
			qa_db_query_sub(
				"SELECT COUNT(*) FROM ^posts
				 WHERE type='Q'
				 AND acount > 0
				 AND (
					tags IS NULL
					OR tags = ''
					OR NOT ($tag_condition)
				 )"
			),
			true
		);
	}

	private function special_tag_condition()
	{
		return "tags = 'خاص'
			OR tags LIKE 'خاص,%'
			OR tags LIKE '%,خاص'
			OR tags LIKE '%,خاص,%'";
	}

	private function normalized_created_expression()
	{
		return "CASE
			WHEN created > 2147483647 THEN created
			ELSE FROM_UNIXTIME(created)
		END";
	}

	private function fetch_avg_first_answer_time($days)
	{
		$since = time() - ((int) $days * 86400);
		$created_expr = $this->normalized_created_expression();

		$result = qa_db_read_one_value(
			qa_db_query_sub(
				"SELECT AVG(TIMESTAMPDIFF(SECOND, q.q_created, a.first_answer_created))
				 FROM (
					SELECT postid, ($created_expr) AS q_created
					FROM ^posts
					WHERE type='Q'
					AND ($created_expr) >= FROM_UNIXTIME(#)
				 ) AS q
				 JOIN (
					SELECT parentid, MIN($created_expr) AS first_answer_created
					FROM ^posts
					WHERE type='A'
					GROUP BY parentid
				 ) AS a
				 ON a.parentid = q.postid",
				$since
			),
			true
		);

		return $result !== null ? (int) round($result) : 0;
	}

	private function format_duration_seconds($seconds)
	{
		$seconds = max(0, (int) $seconds);
		if ($seconds === 0) {
			return 'غير متوفر';
		}

		$hours = floor($seconds / 3600);
		$minutes = floor(($seconds % 3600) / 60);
		$days = floor($hours / 24);
		$remaining_hours = $hours % 24;

		if ($days > 0) {
			return $days . ' يوم ' . $remaining_hours . ' ساعة';
		}

		if ($hours > 0) {
			return $hours . ' ساعة ' . $minutes . ' دقيقة';
		}

		return $minutes . ' دقيقة';
	}

	private function fetch_period_post_counts($days)
	{
		$since = time() - ((int) $days * 86400);
		$created_expr = $this->normalized_created_expression();

		$row = qa_db_read_one_assoc(
			qa_db_query_sub(
				"SELECT
					SUM(CASE WHEN type='Q' THEN 1 ELSE 0 END) AS questions,
					SUM(CASE WHEN type='A' THEN 1 ELSE 0 END) AS answers,
					SUM(CASE WHEN type='C' THEN 1 ELSE 0 END) AS comments
				 FROM ^posts
				 WHERE ($created_expr) >= FROM_UNIXTIME(#)",
				$since
			)
		);

		return array(
			'questions' => (int) $row['questions'],
			'answers' => (int) $row['answers'],
			'comments' => (int) $row['comments'],
		);
	}

	private function calculate_health_index()
	{
		$since = time() - (7 * 86400);
		$three_days_ago = time() - (3 * 86400);
		$created_expr = $this->normalized_created_expression();

		$questions_7 = (int) qa_db_read_one_value(
			qa_db_query_sub(
				"SELECT COUNT(*) FROM ^posts
				 WHERE type='Q'
				 AND ($created_expr) >= FROM_UNIXTIME(#)",
				$since
			),
			true
		);

		$answered_questions_7 = (int) qa_db_read_one_value(
			qa_db_query_sub(
				"SELECT COUNT(*) FROM ^posts
				 WHERE type='Q'
				 AND acount > 0
				 AND ($created_expr) >= FROM_UNIXTIME(#)",
				$since
			),
			true
		);

		$unanswered_over_3_days_7 = (int) qa_db_read_one_value(
			qa_db_query_sub(
				"SELECT COUNT(*) FROM ^posts
				 WHERE type='Q'
				 AND acount = 0
				 AND ($created_expr) >= FROM_UNIXTIME(#)
				 AND ($created_expr) < FROM_UNIXTIME(#)",
				$since,
				$three_days_ago
			),
			true
		);

		$answers_7 = (int) qa_db_read_one_value(
			qa_db_query_sub(
				"SELECT COUNT(*) FROM ^posts
				 WHERE type='A'
				 AND ($created_expr) >= FROM_UNIXTIME(#)",
				$since
			),
			true
		);

		$timeline = $this->fetch_daily_timeline(7);
		$days_without_answers = 0;
		$max_consecutive_without_answers = 0;
		$current_streak = 0;
		foreach ($timeline as $entry) {
			if ((int) $entry['answers'] === 0) {
				$days_without_answers++;
				$current_streak++;
				if ($current_streak > $max_consecutive_without_answers) {
					$max_consecutive_without_answers = $current_streak;
				}
			} else {
				$current_streak = 0;
			}
		}

		$score = 100;
		$score -= min(30, $unanswered_over_3_days_7 * 3);
		$score -= min(25, $max_consecutive_without_answers * 5);

		if ($questions_7 > 0 && $answers_7 > $questions_7) {
			$score += 5;
		}

		$score = max(0, min(100, $score));

		if ($score >= 80) {
			$status = 'صحي';
		} elseif ($score >= 50) {
			$status = 'تحذير';
		} else {
			$status = 'خطر';
		}

		$summary = 'يعتمد المؤشر على آخر 7 أيام فقط.';
		if ($unanswered_over_3_days_7 > 0) {
			$summary .= ' هناك أسئلة غير مجاب عنها تجاوزت 3 أيام.';
		} elseif ($max_consecutive_without_answers > 0) {
			$summary .= ' توجد أيام بدون إجابات.';
		} else {
			$summary .= ' التفاعل جيد والإجابات منتظمة.';
		}

		return array(
			'score' => $score,
			'status' => $status,
			'summary' => $summary,
			'questions_7' => $questions_7,
			'answered_questions_7' => $answered_questions_7,
			'unanswered_over_3_days_7' => $unanswered_over_3_days_7,
			'answers_7' => $answers_7,
			'days_without_answers' => $days_without_answers,
		);
	}

	private function render_dashboard($totals, $periods, $ratios, $timeline, $health, $avg_first_answer_7, $avg_first_answer_30)
	{
		$rows = array();
		$rows[] = '<div class="qa-islamq2a-stats" style="direction: rtl; font-family: Tahoma, Arial, sans-serif; color: #1f2937;">';
		$rows[] = '<style>
			.qa-islamq2a-stats h2 { margin: 24px 0 12px; font-size: 18px; color: #111827; }
			.qa-islamq2a-stats .qa-form-tall-table { width: 100%; border-collapse: collapse; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.08); border-radius: 8px; overflow: hidden; }
			.qa-islamq2a-stats .qa-form-tall-table th { background: #f3f4f6; color: #111827; font-weight: 600; padding: 10px 12px; text-align: center; border-bottom: 1px solid #e5e7eb; }
			.qa-islamq2a-stats .qa-form-tall-table td { padding: 10px 12px; text-align: center; border-bottom: 1px solid #f3f4f6; }
			.qa-islamq2a-stats .qa-form-tall-table tr:nth-child(even) td { background: #fafafa; }
			.qa-islamq2a-stats .qa-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-top: 12px; }
			.qa-islamq2a-stats .qa-stat-card { background: #ffffff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px 16px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
			.qa-islamq2a-stats .qa-stat-card h3 { margin: 0 0 6px; font-size: 14px; color: #6b7280; }
			.qa-islamq2a-stats .qa-stat-card .qa-stat-number { font-size: 20px; font-weight: 700; color: #111827; }
			.qa-islamq2a-stats .qa-stat-card.qa-stat-small .qa-stat-number { font-size: 16px; font-weight: 600; }
		</style>';
		$rows[] = '<h2>الإجماليات</h2>';
		$rows[] = '<div class="qa-stats-grid">';
		$rows[] = '<div class="qa-stat-card"><h3>الأسئلة</h3><div class="qa-stat-number">' . qa_html($totals['questions']) . '</div></div>';
		$rows[] = '<div class="qa-stat-card"><h3>الإجابات</h3><div class="qa-stat-number">' . qa_html($totals['answers']) . '</div></div>';
		$rows[] = '<div class="qa-stat-card"><h3>التعليقات</h3><div class="qa-stat-number">' . qa_html($totals['comments']) . '</div></div>';
		$rows[] = '<div class="qa-stat-card"><h3>المستخدمون</h3><div class="qa-stat-number">' . qa_html($totals['users']) . '</div></div>';
		$rows[] = '<div class="qa-stat-card"><h3>أسئلة الوسم (خاص) المجاب عنها</h3><div class="qa-stat-number">' . qa_html($totals['duplicate_answered']) . '</div></div>';
		$rows[] = '<div class="qa-stat-card"><h3>أسئلة الوسم (خاص) غير المجاب عنها</h3><div class="qa-stat-number">' . qa_html($totals['duplicate_unanswered']) . '</div></div>';
		$rows[] = '<div class="qa-stat-card"><h3>إجمالي أسئلة الوسم (خاص)</h3><div class="qa-stat-number">' . qa_html($totals['duplicate_total']) . '</div></div>';
		$rows[] = '<div class="qa-stat-card"><h3>الأسئلة المجاب عنها المنشورة</h3><div class="qa-stat-number">' . qa_html($totals['answered_public']) . '</div></div>';
		$rows[] = '<div class="qa-stat-card"><h3>الأسئلة غير المجاب عنها</h3><div class="qa-stat-number">' . qa_html($totals['unanswered']) . '</div></div>';
		$rows[] = '<div class="qa-stat-card"><h3>أسئلة مرّ عليها 7 أيام ولم تُجب</h3><div class="qa-stat-number">' . qa_html($totals['unanswered_over_7_days']) . '</div></div>';
		$rows[] = '</div>';

		$rows[] = '<h2>مؤشر صحة المنصة (آخر 7 أيام)</h2>';
		$rows[] = '<div class="qa-stats-grid">';
		$rows[] = '<div class="qa-stat-card"><h3>مؤشر الصحة</h3><div class="qa-stat-number">' . qa_html($health['score']) . ' / 100</div></div>';
		$rows[] = '<div class="qa-stat-card"><h3>الحالة</h3><div class="qa-stat-number">' . qa_html($health['status']) . '</div></div>';
		$rows[] = '<div class="qa-stat-card"><h3>شرح مختصر</h3><div class="qa-stat-number" style="font-size:14px; font-weight:600;">' . qa_html($health['summary']) . '</div></div>';
		$rows[] = '<div class="qa-stat-card"><h3>متوسط زمن أول إجابة (7 أيام)</h3><div class="qa-stat-number">' . qa_html($this->format_duration_seconds($avg_first_answer_7)) . '</div></div>';
		$rows[] = '<div class="qa-stat-card qa-stat-small"><h3>متوسط زمن أول إجابة (30 يومًا)</h3><div class="qa-stat-number">' . qa_html($this->format_duration_seconds($avg_first_answer_30)) . '</div></div>';
		$rows[] = '</div>';

		$rows[] = '<h2>تفاصيل المؤشر (آخر 7 أيام)</h2>';
		$rows[] = '<table class="qa-form-tall-table">';
		$rows[] = '<tr><th>المؤشر</th><th>القيمة</th></tr>';
		$rows[] = '<tr><td>عدد الأسئلة الجديدة</td><td>' . qa_html($health['questions_7']) . '</td></tr>';
		$rows[] = '<tr><td>عدد الأسئلة التي تمّت الإجابة عنها</td><td>' . qa_html($health['answered_questions_7']) . '</td></tr>';
		$rows[] = '<tr><td>عدد الأسئلة غير المجاب عنها (أكثر من 3 أيام)</td><td>' . qa_html($health['unanswered_over_3_days_7']) . '</td></tr>';
		$rows[] = '<tr><td>عدد الإجابات</td><td>' . qa_html($health['answers_7']) . '</td></tr>';
		$rows[] = '<tr><td>عدد الأيام بدون أي إجابة</td><td>' . qa_html($health['days_without_answers']) . '</td></tr>';
		$rows[] = '</table>';

		$rows[] = '<h2>النشاط (يومي / أسبوعي / شهري)</h2>';
		$rows[] = '<table class="qa-form-tall-table">';
		$rows[] = '<tr><th>الفترة</th><th>الأسئلة</th><th>الإجابات</th><th>التعليقات</th></tr>';
		foreach ($periods as $label => $counts) {
			$arabic_label = $label === 'daily' ? 'اليوم' : ($label === 'weekly' ? 'الأسبوع' : 'الشهر');
			$rows[] = '<tr><td>' . qa_html($arabic_label) . '</td><td>' . qa_html($counts['questions']) . '</td><td>' . qa_html($counts['answers']) . '</td><td>' . qa_html($counts['comments']) . '</td></tr>';
		}
		$rows[] = '</table>';

		$rows[] = '<h2>النسب</h2>';
		$rows[] = '<table class="qa-form-tall-table">';
		$rows[] = '<tr><th>المؤشر</th><th>القيمة</th></tr>';
		$rows[] = '<tr><td>نسبة الإجابات إلى الأسئلة (%)</td><td>' . qa_html($ratios['answers_per_question']) . '</td></tr>';
		$rows[] = '<tr><td>نسبة التعليقات إلى الأسئلة (%)</td><td>' . qa_html($ratios['comments_per_question']) . '</td></tr>';
		$rows[] = '</table>';

		$rows[] = '<h2>نشاط آخر 30 يومًا</h2>';
		$rows[] = '<table class="qa-form-tall-table">';
		$rows[] = '<tr><th>التاريخ</th><th>الأسئلة</th><th>الإجابات</th><th>التعليقات</th></tr>';
		foreach ($timeline as $entry) {
			$rows[] = '<tr><td>' . qa_html($entry['day']) . '</td><td>' . qa_html($entry['questions']) . '</td><td>' . qa_html($entry['answers']) . '</td><td>' . qa_html($entry['comments']) . '</td></tr>';
		}
		$rows[] = '</table>';
		$rows[] = '</div>';

		return implode("\n", $rows);
	}
}

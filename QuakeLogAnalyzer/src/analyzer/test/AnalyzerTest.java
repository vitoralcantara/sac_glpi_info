package analyzer.test;

import static org.junit.Assert.*;

import java.io.File;
import java.io.FileOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.io.InputStreamReader;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.List;
import java.util.Map;

import org.junit.Rule;
import org.junit.Test;
import org.junit.rules.ExternalResource;

public class AnalyzerTest {

	@Rule
	public ResourceFile log1 = new ResourceFile("/log1.log");

	@Test
	public void testCorrectResult() {
		QuakeLogAnalyzedResults qlar = QuakeLogAnalyzer.parse(log1.getFile());
		int expectedTotalKills = 0;
		int actualTotalKills = qlar.getTotalKills();

		assertEquals(expectedTotalKills, actualTotalKills);

		List<String> expectedPlayers = new ArrayList<String>();
		List<String> actualPlayers = qlar.getPlayers();

		assertEquals(expectedPlayers, actualPlayers);

		Map<String, String> expectedKills = new HashMap<String, String>();
		Map<String, String> actualKills = qlar.getKills();

		assertEquals(expectedKills, actualKills);

		Map<String, String> expectedMeanKills = new HashMap<String, String>();
		Map<String, String> actualMeanKills = qlar.getKillGroupedByMeans();

		assertEquals(expectedMeanKills, actualMeanKills);
	}

	@Test
	public void testFormattedOutput() {
		try{
			QuakeLogAnalyzedResults qlar = QuakeLogAnalyzer.parse(log1.getFile());
			String expectedOutput = "";
			String actualOutput = qlar.parsedOutput();
			assertEquals(expectedOutput, actualOutput);
		}catch Wrong
		
	}
}
